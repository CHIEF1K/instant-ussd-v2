<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;




class USSDController extends Controller
{
    public function handleUSSDRequest(Request $request,$merchant_id)
    {
        //return response()->json(['message' => 'Handling USSD request for merchant_id: ' . $merchant_id]);

        $mobile = $request->Mobile;
        $session_id = $request->SessionId;
        $service_code = $request->ServiceCode;
        $type = $request->Type;
        $message = $request->Message;
        $operator = $request->Operator;

        $response = array();

        $apiResponseData = $this->sendPostRequest($mobile);

        $firstName = $apiResponseData['response']['firstname'] ?? ''; // Default to an empty string if not present

        Log::info("Extracted firstName: $firstName");



        // Querying merchant using both ussd_code and merchant_id
        $merchant = DB::table('merchants')
                     ->where('ussd_code', $service_code)
                     ->where('merchant_id', $merchant_id)
                     ->first();

        if ($type === "initiation") {
            if ($merchant) {
                $merchants_name = $merchant->merchants_name;
                $merchant_id = $merchant->merchant_id;
                $response = array(
                    "Type" => "Response",
                    "Message" => "Welcome to " . $merchants_name . ".\nPlease enter the amount to pay:"
                );
            } else {
                $response = array(
                    "Type" => "Release",
                    "Message" => "Sorry, This Merchant is not registered."
                );
            }
        } elseif ($type === "response") {
            $amount = trim($message);

            if (!is_numeric($amount)) {
                $response_message = "Invalid amount entered. Please try again.";
            } elseif ($amount <= 0) {
                $response_message = "Amount must be greater than zero. Please try again.";
            } else {
                if ($merchant) {
                    $app_id = $merchant->app_id;
                    $app_key = $merchant->app_key;
                  //  $merchants_name = $merchant->merchants_name;
                    $merchant_id = $merchant->merchant_id;
                    $order_id = Str::random(12);

                    $json_data = array(
                        "app_id" => $app_id,
                        "app_key" => $app_key,
                        "name" => $firstName,
                        "FeeTypeCode" => "GENERALPAYMENT",
                        "mobile" => $mobile,
                        "currency" => "GHS",
                        "amount" => $amount,
                        "mobile_network" => strtoupper($operator),
                        "order_id" => $order_id,
                        "order_desc" => "Payment",
                    );

                    $post_data = json_encode($json_data, JSON_UNESCAPED_SLASHES);

                    $curl = curl_init();

                    curl_setopt_array($curl, array(
                        CURLOPT_URL => "https://api.interpayafrica.com/v3/interapi.svc/CreateMMPayment",
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_ENCODING => "",
                        CURLOPT_MAXREDIRS => 10,
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_CUSTOMREQUEST => "POST",
                        CURLOPT_POSTFIELDS => $post_data,
                        CURLOPT_HTTPHEADER => array(
                            "Content-Type: application/json",
                        ),
                    ));

                    $response_message = curl_exec($curl);
                    $err = curl_error($curl);

                    curl_close($curl);

                    if ($err) {
                        $response_message = "E1. Please Try Again Later.";
                    } else {
                        $return = json_decode($response_message);
                        $params = json_decode($response_message, true);


                        if ($paymentTransaction = DB::table('payment_transactions')->where('order_id', $order_id)->first()) {
                            $transaction_id = $paymentTransaction->id;


                            DB::table('payment_transactions')
                            ->where('id', $transaction_id)
                            ->update([
                                'status_code' => $return->status_code,
                                'amount' => $amount,
                                'status_message' => $return->status_message,
                                'merchantcode' => $return->merchantcode,
                                'transaction_no' => $return->transaction_no,
                                'resource_id' => $mobile,
                                'transaction_type' => 'payment',
                                'order_id' => $order_id,
                                'merchant_name'=> $merchant_id,
                                'client_timestamp' => DB::raw('CURRENT_TIMESTAMP'),
                            ]);

                        } else {
                            $sql = "INSERT INTO payment_transactions 
                                    (status_code, amount, status_message, merchantcode, transaction_no, resource_id, transaction_type, order_id, merchants_name, client_timestamp) 
                                    VALUES 
                                    (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";

                            DB::insert($sql, [
                                $return->status_code,
                                $amount,
                                $return->status_message,
                                $return->merchantcode,
                                $return->transaction_no,
                                $mobile,
                                'payment',
                                $order_id,
                                $merchant_id,
                            ]);
                        }

                        $paymentTransaction = DB::table('mother_merchants.payment_transactions')->where('order_id', $order_id)->first();

                        if ($return->status_code == 1) {
                            $response_message = "You will receive a payment prompt to complete your payment";
                        } else {
                            $response_message = "E3. Please Try Again Later.";
                        }
                    }

                    $response = [
                        "Type" => "Release",
                        "Message" => $response_message
                    ];
                } else {
                    $response_message = "Sorry, This Merchant is not registered.";
                }
            }
        }

        return response()->json($response);
    }

    private function sendPostRequest($mobile)
    {
        // Build the URL for the POST request with the number appended
        $url = "https://emergentghanadev.com/api/name-validation/live/$mobile";
    
        try {
            // Make an HTTP POST request to the URL
            $response = Http::post($url);
    
            // Log the response
            Log::info("API Response: " . $response->body());
    
            // Decode the JSON response and store it in an array
            $apiResponse = $response->json();
    
            // You can access the values like this
            $statusCode = $apiResponse['status_code'];
            $statusMessage = $apiResponse['status_message'];
            $firstName = $apiResponse['firstname'];
            $surname = $apiResponse['surname'];
            $valid = $apiResponse['valid'];
    
            // Save the response in an array along with the phone number
            $responseData = ['phone_number' => $mobile, 'response' => $apiResponse];
    
            return $responseData;
        } catch (\Exception $e) {
            // Handle exceptions (e.g., connection issues)
            Log::error("HTTP Request Error: " . $e->getMessage());
            // You may want to return or log an error response here
            return ['phone_number' => $mobile, 'error' => $e->getMessage()];
        }
    }
}
