<?php
################################################################################
# Name: OpenPanel WHMCS Module
# Usage: https://openpanel.co/docs/changelog/0.1.7/#whmcs-module
# Source: https://github.com/stefanpejcic/openpanel-whmcs-module
# Author: Stefan Pejcic
# Created: 01.05.2024
# Last Modified: 30.05.2024
# Company: openpanel.co
# Copyright (c) openpanel.co
# 
# Permission is hereby granted, free of charge, to any person obtaining a copy
# of this software and associated documentation files (the "Software"), to deal
# in the Software without restriction, including without limitation the rights
# to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
# copies of the Software, and to permit persons to whom the Software is
# furnished to do so, subject to the following conditions:
# 
# The above copyright notice and this permission notice shall be included in
# all copies or substantial portions of the Software.
# 
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
# IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
# FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
# AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
# LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
# OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
# THE SOFTWARE.
################################################################################



############### CORE STUFF ##################
# BASIC AUTH, SHOULD BE REUSED IN ALL ROUTES
function generateJWTToken($params) {
    $apiProtocol = filter_var($params["serverhostname"], FILTER_VALIDATE_IP) === false ? 'https://' : 'http://';
    $authEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/';



    // Prepare cURL request to authenticate
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $authEndpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array(
            'username' => $params["serverusername"],
            'password' => $params["serverpassword"]
        )),
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json"
        ),
    ));

    // Execute cURL request to authenticate
    $response = curl_exec($curl);

    // Check for errors
    if (curl_errno($curl)) {
        $token = "cURL Error: " . curl_error($curl);
    } else {
        // Decode the response JSON to get the token
        $responseData = json_decode($response, true);
        $token = isset($responseData['token']) ? $responseData['token'] : "Token not found in response";
    }

    // Close cURL session
    curl_close($curl);

    return $token;
}

############### USER ACTIONS ################
# CREATE ACCOUNT
function openpanel_CreateAccount($params) {
    $apiProtocol = filter_var($params["serverhostname"], FILTER_VALIDATE_IP) === false ? 'https://' : 'http://';
    $authEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/';

    // Prepare cURL request to authenticate
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $authEndpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array(
            'username' => $params["serverusername"],
            'password' => $params["serverpassword"]
        )),
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json"
        ),
    ));

    // Execute cURL request to authenticate
    $response = curl_exec($curl);

    // Check for errors
    if (curl_errno($curl)) {
        $result = json_encode(array(
            "success" => false,
            "message" => "cURL Error: " . curl_error($curl)
        ));
    } else {
        // Decode the response JSON to get the token
        $responseData = json_decode($response, true);
        $jwtToken = isset($responseData['access_token']) ? $responseData['access_token'] : false;

        if ($jwtToken) {
            // Prepare API endpoint for user creation
            $createUserEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/users';

            // Prepare data for user creation
            $userData = array(
                'username' => $params["username"],
                'password' => $params["password"],
                'email' => $params["clientsdetails"]["email"],
                'plan_name' => $params["configoption1"]
            );

            // Prepare cURL request for user creation
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $createUserEndpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($userData),
                CURLOPT_HTTPHEADER => array(
                    "Authorization: Bearer " . $jwtToken,
                    "Content-Type: application/json"
                ),
            ));

            // Execute cURL request for user creation
            $response = curl_exec($curl);

            // Decode the response JSON
            $responseData = json_decode($response, true);

            // If the user creation is successful
            if ($responseData && isset($responseData['response']['message'])) {
                // Format the success response
                $result = json_encode(array(
                    "success" => true,
                    "message" => $responseData['response']['message']
                ));
            } else {
                // Handle error cases
                $result = json_encode(array(
                    "success" => false,
                    "message" => "User creation failed"
                ));
            }

            // Close cURL session
            curl_close($curl);
        } else {
            $result = json_encode(array(
                "success" => false,
                "message" => "Token not found in response"
            ));
        }
    }

    return $result;
}

# TERMINATE ACCOUNT
function openpanel_TerminateAccount($params) {
    $apiProtocol = filter_var($params["serverhostname"], FILTER_VALIDATE_IP) === false ? 'https://' : 'http://';
    $authEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/';

    // Prepare cURL request to authenticate
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $authEndpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array(
            'username' => $params["serverusername"],
            'password' => $params["serverpassword"]
        )),
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json"
        ),
    ));

    // Execute cURL request to authenticate
    $response = curl_exec($curl);

    // Check for errors
    if (curl_errno($curl)) {
        $result = json_encode(array(
            "success" => false,
            "message" => "cURL Error: " . curl_error($curl)
        ));
    } else {
        // Decode the response JSON to get the token
        $responseData = json_decode($response, true);
        $jwtToken = isset($responseData['access_token']) ? $responseData['access_token'] : false;

        if ($jwtToken) {
            // Prepare API endpoint for user termination
            $terminateUserEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/users/' . $params["username"];

            // Prepare cURL request for user termination
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $terminateUserEndpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'DELETE',
                CURLOPT_HTTPHEADER => array(
                    "Authorization: Bearer " . $jwtToken,
                    "Content-Type: application/json"
                ),
            ));

            // Execute cURL request for user termination
            $response = curl_exec($curl);

            // Decode the response JSON
            $responseData = json_decode($response, true);

            // If the user termination is successful
            if ($responseData && isset($responseData['response']['message'])) {
                // Format the success response
                $result = json_encode(array(
                    "success" => true,
                    "message" => $responseData['response']['message']
                ));
            } else {
                // Handle error cases
                $result = json_encode(array(
                    "success" => false,
                    "message" => "User termination failed"
                ));
            }

            // Close cURL session
            curl_close($curl);
        } else {
            $result = json_encode(array(
                "success" => false,
                "message" => "Token not found in response"
            ));
        }
    }

    return $result;
}

# CHANGE PASSWORD FOR ACCOUNT
function openpanel_ChangePassword($params) {
    $apiProtocol = filter_var($params["serverhostname"], FILTER_VALIDATE_IP) === false ? 'https://' : 'http://';
    $authEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/';


    # example 
    # curl -X PATCH "http://64.23.205.3:2087/api/users/stefan341" -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJmcmVzaCI6ZmFsc2UsImlhdCI6MTcxNDU1NjE1MiwianRpIjoiM2VjZTA5YWEtYWI1ZS00YzVkLWJhYjItNWZiYTEwNWRlZWMxIiwidHlwZSI6ImFjY2VzcyIsInN1YiI6InN0ZWZhbiIsIm5iZiI6MTcxNDU1NjE1MiwiY3NyZiI6Ijk2MzVjYWE5LTgxZmYtNDMxZS04YzE2LWQxNGIwNGJjNGFjZCIsImV4cCI6MTcxNDU1NzA1Mn0.AFIRP_8FdhPiF16mbmsgNQN2EtDK-0nNvgd0kZCsctc" -H "Content-Type: application/json" -d '{"password":"kQsUFhwkzBCw3M57"}'
    #
    #

    // Prepare cURL request to authenticate
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $authEndpoint,
        CURLOPT_RETURNTRANSFER => true,
        
        CURLOPT_SSL_VERIFYPEER, false,
        CURLOPT_SSL_VERIFYHOST, false,
        
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array(
            'username' => $params["serverusername"],
            'password' => $params["serverpassword"]
        )),
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json"
        ),
    ));

    // Execute cURL request to authenticate
    $response = curl_exec($curl);

    // Check for errors
    if (curl_errno($curl)) {
        $result = json_encode(array(
            "success" => false,
            "message" => "cURL Error: " . curl_error($curl)
        ));
    } else {
        // Decode the response JSON to get the token
        $responseData = json_decode($response, true);
        $jwtToken = isset($responseData['access_token']) ? $responseData['access_token'] : false;

        if ($jwtToken) {
            // Prepare API endpoint for password change
            $changePasswordEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/users/' . $params["username"];

            // Prepare data for password change
            $passwordData = array(
                'password' => $params["password"]
            );

            // Prepare cURL request for password change
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $changePasswordEndpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_POSTFIELDS => json_encode($passwordData),
                CURLOPT_HTTPHEADER => array(
                    "Authorization: Bearer " . $jwtToken,
                    "Content-Type: application/json"
                ),
            ));

            // Execute cURL request for password change
            $response = curl_exec($curl);

            // Decode the response JSON
            $responseData = json_decode($response, true);

            // If the password change is successful
            if ($responseData && isset($responseData['success']) && $responseData['success'] === true) {
                // Format the success response
                $result = json_encode(array(
                    "success" => true,
                    "message" => "Password changed successfully"
                ));
            } else {
                // Handle error cases
                $result = json_encode(array(
                    "success" => false,
                    "message" => "Password change failed"
                ));
            }

            // Close cURL session
            curl_close($curl);
        } else {
            $result = json_encode(array(
                "success" => false,
                "message" => "Token not found in response"
            ));
        }
    }

    return $result;
}

# SUSPEND ACCOUNT
function openpanel_SuspendAccount($params) {
    $apiProtocol = filter_var($params["serverhostname"], FILTER_VALIDATE_IP) === false ? 'https://' : 'http://';
    $authEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/';

    // Prepare cURL request to authenticate
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $authEndpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array(
            'username' => $params["serverusername"],
            'password' => $params["serverpassword"]
        )),
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json"
        ),
    ));

    // Execute cURL request to authenticate
    $response = curl_exec($curl);

    // Check for errors
    if (curl_errno($curl)) {
        $result = json_encode(array(
            "success" => false,
            "message" => "cURL Error: " . curl_error($curl)
        ));
    } else {
        // Decode the response JSON to get the token
        $responseData = json_decode($response, true);
        $jwtToken = isset($responseData['access_token']) ? $responseData['access_token'] : false;

        if ($jwtToken) {
            // Prepare API endpoint for account suspension
            $suspendAccountEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/users/' . $params["username"];

            // Prepare data for account suspension
            $suspendData = array(
                'action' => 'suspend'
            );

            // Prepare cURL request for account suspension
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $suspendAccountEndpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_POSTFIELDS => json_encode($suspendData),
                CURLOPT_HTTPHEADER => array(
                    "Authorization: Bearer " . $jwtToken,
                    "Content-Type: application/json"
                ),
            ));

            // Execute cURL request for account suspension
            $response = curl_exec($curl);

            // Decode the response JSON
            $responseData = json_decode($response, true);

            // If the account suspension is successful
            if ($responseData && isset($responseData['success']) && $responseData['success'] === true) {
                // Format the success response
                $result = json_encode(array(
                    "success" => true,
                    "message" => "Account suspended successfully"
                ));
            } else {
                // Handle error cases
                $result = json_encode(array(
                    "success" => false,
                    "message" => "Account suspension failed"
                ));
            }

            // Close cURL session
            curl_close($curl);
        } else {
            $result = json_encode(array(
                "success" => false,
                "message" => "Token not found in response"
            ));
        }
    }

    return $result;
}

# UNSUSPEND ACCOUNT
function openpanel_UnsuspendAccount($params) {
    $apiProtocol = filter_var($params["serverhostname"], FILTER_VALIDATE_IP) === false ? 'https://' : 'http://';
    $authEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/';

    // Prepare cURL request to authenticate
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $authEndpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array(
            'username' => $params["serverusername"],
            'password' => $params["serverpassword"]
        )),
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json"
        ),
    ));

    // Execute cURL request to authenticate
    $response = curl_exec($curl);

    // Check for errors
    if (curl_errno($curl)) {
        $result = json_encode(array(
            "success" => false,
            "message" => "cURL Error: " . curl_error($curl)
        ));
    } else {
        // Decode the response JSON to get the token
        $responseData = json_decode($response, true);
        $jwtToken = isset($responseData['access_token']) ? $responseData['access_token'] : false;

        if ($jwtToken) {
            // Prepare API endpoint for account unsuspension
            $unsuspendAccountEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/users/' . $params["username"];

            // Prepare data for account unsuspension
            $unsuspendData = array(
                'action' => 'unsuspend'
            );

            // Prepare cURL request for account unsuspension
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $unsuspendAccountEndpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_POSTFIELDS => json_encode($unsuspendData),
                CURLOPT_HTTPHEADER => array(
                    "Authorization: Bearer " . $jwtToken,
                    "Content-Type: application/json"
                ),
            ));

            // Execute cURL request for account unsuspension
            $response = curl_exec($curl);

            // Decode the response JSON
            $responseData = json_decode($response, true);

            // If the account unsuspension is successful
            if ($responseData && isset($responseData['success']) && $responseData['success'] === true) {
                // Format the success response
                $result = json_encode(array(
                    "success" => true,
                    "message" => "Account unsuspended successfully"
                ));
            } else {
                // Handle error cases
                $result = json_encode(array(
                    "success" => false,
                    "message" => "Account unsuspension failed"
                ));
            }

            // Close cURL session
            curl_close($curl);
        } else {
            $result = json_encode(array(
                "success" => false,
                "message" => "Token not found in response"
            ));
        }
    }

    return $result;
}

# TODO: CHANGE PLAN NOT WORKING!!!!
function openpanel_ChangePackage($params) {
    $apiProtocol = filter_var($params["serverhostname"], FILTER_VALIDATE_IP) === false ? 'https://' : 'http://';
    $authEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/';

    // Prepare cURL request to authenticate
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $authEndpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array(
            'username' => $params["serverusername"],
            'password' => $params["serverpassword"]
        )),
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json"
        ),
    ));

    // Execute cURL request to authenticate
    $response = curl_exec($curl);

    // Check for errors
    if (curl_errno($curl)) {
        // Handle cURL error
        $result = array(
            "success" => false,
            "message" => "Unable to authenticate with OpenPanel. Please try again later."
        );
    } else {
        // Decode the response JSON to get the token
        $responseData = json_decode($response, true);
        $jwtToken = isset($responseData['access_token']) ? $responseData['access_token'] : false;

        if ($jwtToken) {
            // Prepare API endpoint for changing user plan
            $changePlanEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/users/' . $params["username"];

            // Prepare data for changing plan
            $planData = array(
                'plan_name' => $params["configoption1"]
            );

            // Prepare cURL request for changing plan
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $changePlanEndpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => json_encode($planData),
                CURLOPT_HTTPHEADER => array(
                    "Authorization: Bearer " . $jwtToken,
                    "Content-Type: application/json"
                ),
            ));

            // Execute cURL request for changing plan
            $response = curl_exec($curl);

            // If the plan is changed successfully
            if ($response) {
                // Format the success response
                $result = array(
                    "success" => true,
                    "message" => "Plan changed successfully"
                );
            } else {
                // Handle error cases
                $result = array(
                    "success" => false,
                    "message" => "Failed to change plan"
                );
            }

            // Close cURL session
            curl_close($curl);
        } else {
            // Handle token not found error
            $result = array(
                "success" => false,
                "message" => "Token not found in response from OpenPanel API"
            );
        }
    }

    return $result;
}


############### AUTOLOGIN LINKS ##############

# LOGIN FOR USERS ON FRONT
function openpanel_ClientArea($params) {
    $apiProtocol = filter_var($params["serverhostname"], FILTER_VALIDATE_IP) === false ? 'https://' : 'http://';
    $authEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/';

    // Prepare cURL request to authenticate
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $authEndpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array(
            'username' => $params["serverusername"],
            'password' => $params["serverpassword"]
        )),
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json"
        ),
    ));

    // Execute cURL request to authenticate
    $response = curl_exec($curl);

    // Check for errors
    if (curl_errno($curl)) {
        // Handle cURL error
        $code = '<p>Error: Unable to authenticate with OpenPanel. Please try again later.</p>';
    } else {
        // Decode the response JSON to get the token
        $responseData = json_decode($response, true);
        $jwtToken = isset($responseData['access_token']) ? $responseData['access_token'] : false;

        if ($jwtToken) {
            // Prepare API endpoint for getting login link
            $getLoginLinkEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/users/' . $params["username"];

            // Prepare data for login link generation
            $loginData = array();

            // Prepare cURL request for getting login link
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $getLoginLinkEndpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'CONNECT',
                CURLOPT_POSTFIELDS => json_encode($loginData),
                CURLOPT_HTTPHEADER => array(
                    "Authorization: Bearer " . $jwtToken,
                    "Content-Type: application/json"
                ),
            ));

            // Execute cURL request for getting login link
            $response = curl_exec($curl);

            // Decode the response JSON
            $responseData = json_decode($response, true);

            if ($responseData && isset($responseData['link'])) {
                $code = '<script>
                            function loginOpenPanelButton() {
                                var openpanel_btn = document.getElementById("loginLink");
                                openpanel_btn.textContent = "Logging in...";
                                document.getElementById("refreshMessage").style.display = "block";
                            }
                        </script>';
                $code .= '<a id="loginLink" class="btn btn-primary" href="' . $responseData['link'] . '" target="_blank" onclick="loginOpenPanelButton()">
                            <svg version="1.0" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 213.000000 215.000000" preserveAspectRatio="xMidYMid meet"><g transform="translate(0.000000,215.000000) scale(0.100000,-0.100000)" fill="currentColor" stroke="none"><path d="M990 2071 c-39 -13 -141 -66 -248 -129 -53 -32 -176 -103 -272 -158 -206 -117 -276 -177 -306 -264 -17 -50 -19 -88 -19 -460 0 -476 0 -474 94 -568 55 -56 124 -98 604 -369 169 -95 256 -104 384 -37 104 54 532 303 608 353 76 50 126 113 147 184 8 30 12 160 12 447 0 395 -1 406 -22 461 -34 85 -98 138 -317 264 -104 59 -237 136 -295 170 -153 90 -194 107 -275 111 -38 2 -81 0 -95 -5z m205 -561 c66 -38 166 -95 223 -127 l102 -58 0 -262 c0 -262 0 -263 -22 -276 -13 -8 -52 -31 -88 -51 -36 -21 -126 -72 -200 -115 l-135 -78 -3 261 -3 261 -166 95 c-91 52 -190 109 -219 125 -30 17 -52 34 -51 39 3 9 424 256 437 255 3 0 59 -31 125 -69z"></path></g></svg> &nbsp; Login to OpenPanel
                        </a>';
                $code .= '<p id="refreshMessage" style="display: none;">One-time login link has already been used, please refresh the page to login again.</p>';
            } else {
                // Handle error cases
                $error_message = '<p>Error: Unable to generate login link for OpenPanel. Please try again later.</p>';
                $error_message .= '<p>Server Response: ' . htmlentities($response) . '</p>';
                $code = $error_message;
            }


            // Close cURL session
            curl_close($curl);
        } else {
            // Handle token not found error
            $code = '<p>Error: Token not found in response from OpenPanel API. Please try again later.</p>';
        }
    }

    return $code;
}

# TODO: LOGIN FROM admin/configservers.php
function openpanel_AdminLink($params) {
    $apiProtocol = filter_var($params["serverhostname"], FILTER_VALIDATE_IP) === false ? 'https://' : 'http://';
    $adminLoginEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/login';


     $code = '<form action="'.$adminLoginEndpoint.'" method="post" target="_blank">
            <input type="hidden" name="user" value="'.$params["serverusername"].'" />
            <input type="hidden" name="password" value="'.$params["serverpassword"].'" />
            <input type="submit" value="Login to OpenAdmin" />
            </form>';
    return $code;
}

# LOGIN FOR ADMINS FROM BACKEND
function openpanel_LoginLink($params) {
    // Check if serverhostname is an IP address or a domain name
    $apiProtocol = filter_var($params["serverhostname"], FILTER_VALIDATE_IP) === false ? 'https://' : 'http://';

    // Prepare API endpoint for authentication
    $authEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/';

    // Prepare cURL request to authenticate
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $authEndpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array(
            'username' => $params["serverusername"],
            'password' => $params["serverpassword"]
        )),
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json"
        ),
    ));

    // Execute cURL request to authenticate
    $response = curl_exec($curl);

    // Check for errors
    if (curl_errno($curl)) {
        // Handle cURL error
        $code = '<p>Error: Unable to authenticate with OpenPanel. Please try again later.</p>';
    } else {
        // Decode the response JSON to get the token
        $responseData = json_decode($response, true);
        $jwtToken = isset($responseData['access_token']) ? $responseData['access_token'] : false;

        if ($jwtToken) {
            // Prepare API endpoint for getting login link
            $getLoginLinkEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/users/' . $params["username"];

            // Prepare data for login link generation
            $loginData = array();

            // Prepare cURL request for getting login link
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $getLoginLinkEndpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'CONNECT',
                CURLOPT_POSTFIELDS => json_encode($loginData),
                CURLOPT_HTTPHEADER => array(
                    "Authorization: Bearer " . $jwtToken,
                    "Content-Type: application/json"
                ),
            ));

            // Execute cURL request for getting login link
            $response = curl_exec($curl);

            // Decode the response JSON
            $responseData = json_decode($response, true);

            if ($responseData && isset($responseData['link'])) {
                $code = '<script>
                            function loginOpenPanelButton() {
                                var openpanel_btn = document.getElementById("loginLink");
                                openpanel_btn.textContent = "Logging in...";
                                document.getElementById("refreshMessage").style.display = "block";
                            }
                        </script>';
                $code .= '<a id="loginLink" class="btn btn-primary" href="' . $responseData['link'] . '" target="_blank" onclick="loginOpenPanelButton()">
                            <svg version="1.0" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 213.000000 215.000000" preserveAspectRatio="xMidYMid meet"><g transform="translate(0.000000,215.000000) scale(0.100000,-0.100000)" fill="currentColor" stroke="none"><path d="M990 2071 c-39 -13 -141 -66 -248 -129 -53 -32 -176 -103 -272 -158 -206 -117 -276 -177 -306 -264 -17 -50 -19 -88 -19 -460 0 -476 0 -474 94 -568 55 -56 124 -98 604 -369 169 -95 256 -104 384 -37 104 54 532 303 608 353 76 50 126 113 147 184 8 30 12 160 12 447 0 395 -1 406 -22 461 -34 85 -98 138 -317 264 -104 59 -237 136 -295 170 -153 90 -194 107 -275 111 -38 2 -81 0 -95 -5z m205 -561 c66 -38 166 -95 223 -127 l102 -58 0 -262 c0 -262 0 -263 -22 -276 -13 -8 -52 -31 -88 -51 -36 -21 -126 -72 -200 -115 l-135 -78 -3 261 -3 261 -166 95 c-91 52 -190 109 -219 125 -30 17 -52 34 -51 39 3 9 424 256 437 255 3 0 59 -31 125 -69z"></path></g></svg> &nbsp; Login to OpenPanel
                        </a>';
                $code .= '<p id="refreshMessage" style="display: none;">One-time login link has already been used, please refresh the page to login again.</p>';
            } else {
                // Handle error cases
                $error_message = '<p>Error: Unable to generate login link for OpenPanel. Please try again later.</p>';
                $error_message .= '<p>Server Response: ' . htmlentities($response) . '</p>';
                $code = $error_message;
            }


            // Close cURL session
            curl_close($curl);
        } else {
            // Handle token not found error
            $code = '<p>Error: Token not found in response from OpenPanel API. Please try again later.</p>';
        }
    }

    return $code;
}

############### MAINTENANCE ################


# TODO: GET USAGE FOR USERS!!!!!!!!
function openpanel_UsageUpdate($params) {

    # resposne should be formated like this:
    #{
    #    "disk_usage": "1024 MB",
    #    "disk_limit": "2048 MB",
    #    "bandwidth_usage": "512 MB",
    #    "bandwidth_limit": "1024 MB"
    #}


    $apiProtocol = filter_var($params["serverhostname"], FILTER_VALIDATE_IP) === false ? 'https://' : 'http://';
    $authEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/';

    // Prepare cURL request to authenticate
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $authEndpoint,
        CURLOPT_RETURNTRANSFER => true,
        
        CURLOPT_SSL_VERIFYPEER, false,
        CURLOPT_SSL_VERIFYHOST, false,
        
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array(
            'username' => $params["serverusername"],
            'password' => $params["serverpassword"]
        )),
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json"
        ),
    ));

    // Execute cURL request to authenticate
    $response = curl_exec($curl);

    // Check for errors
    if (curl_errno($curl)) {
        $result = json_encode(array(
            "success" => false,
            "message" => "cURL Error: " . curl_error($curl)
        ));
    } else {
        // Decode the response JSON to get the token
        $responseData = json_decode($response, true);
        $jwtToken = isset($responseData['access_token']) ? $responseData['access_token'] : false;

        if ($jwtToken) {
            // Prepare API endpoint for password change
            $getUsageEndpoint = $apiProtocol . $params["serverhostname"] . ':2087/api/usage/';

            // Prepare cURL request for password change
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $getUsageEndpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_POSTFIELDS => json_encode($passwordData),
                CURLOPT_HTTPHEADER => array(
                    "Authorization: Bearer " . $jwtToken,
                    "Content-Type: application/json"
                ),
            ));

            // Execute cURL request for password change
            $response = curl_exec($curl);

            // Decode the response JSON
            $usageData = json_decode($response, true);

            // Loop through results and update DB
            foreach ($usageData as $user => $values) {
                update_query("tblhosting", array(
                    "diskusage" => $values['disk_usage'],
                    "disklimit" => $values['disk_limit'],
                    "lastupdate" => "now()"
                ), array("server" => $params['serverid'], "username" => $user));
            }


            // Close cURL session
            curl_close($curl);
        } else {
            $result = json_encode(array(
                "success" => false,
                "message" => "Token not found in response"
            ));
        }
    }

    return $result;
}


?>
