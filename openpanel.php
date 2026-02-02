<?php
################################################################################
# Name: OpenPanel WHMCS Module
# Usage: https://openpanel.com/docs/articles/extensions/openpanel-and-whmcs/
# Source: https://github.com/stefanpejcic/openpanel-whmcs-module
# Author: Stefan Pejcic
# Created: 01.05.2024
# Last Modified: 02.02.2026
# Company: openpanel.com
# Copyright (c) Stefan Pejcic
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


# ======================================================================
# Checks
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


# ======================================================================
# Logging
define('OPENPANEL_DEBUG', true);

function openpanelLog($action, $params = [], $request = null, $response = null, $error = null) {
    logModuleCall('openpanel', $action, $request ?? $params, $response, $error);
}


# ======================================================================
# Helper Functions

// get protocol + base URL
function openpanelBaseUrl($params) {
    $protocol = filter_var($params['serverhostname'], FILTER_VALIDATE_IP) ? 'http://' : 'https://';
    $port = !empty($params['serverport']) ? $params['serverport'] : 2087;
    return $protocol . $params['serverhostname'] . ':' . $port;
}

// fetch token
function getOpenPanelAuthToken($params) {
    $endpoint = openpanelBaseUrl($params) . '/api/';

    $response = curl_exec_with_options([
        CURLOPT_URL => $endpoint,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'username' => $params['serverusername'],
            'password' => decrypt($params['serverpassword'])
        ]),
    ]);

    $data = json_decode($response, true);
    if (!isset($data['access_token'])) {
        openpanelLog('Auth response is missing token', $params, null, $response);
        return false;
    }
    return $data['access_token'];
}

// cURL executor
function curl_exec_with_options(array $options) {
    $curl = curl_init();
    curl_setopt_array($curl, $options + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);

    $response = curl_exec($curl);

    if ($response === false) {
        $error = curl_error($curl);
        if (defined('OPENPANEL_DEBUG') && OPENPANEL_DEBUG) {
            logModuleCall('openpanel', 'cURL Error', $options, null, $error);
        }
        curl_close($curl);
        throw new Exception($error);
    }

    if (defined('OPENPANEL_DEBUG') && OPENPANEL_DEBUG) {
        logModuleCall('openpanel', 'cURL Response', $options, $response, null);
    }

    curl_close($curl);
    return $response;
}

// API request
function apiRequest($params, $uri, $token, $method = 'POST', $data = null) {
    try {
        $response = json_decode(
            curl_exec_with_options([
                CURLOPT_URL => openpanelBaseUrl($params) . $uri,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer $token",
                    "Content-Type: application/json"
                ],
                CURLOPT_POSTFIELDS => $data ? json_encode($data) : null,
            ]),
            true
        );

        openpanelLog('API ' . $uri, $params, $data, $response);

        return $response;
    } catch (Exception $e) {
        openpanelLog('API Exception ' . $uri, $params, $data, null, $e->getMessage());
        throw $e; // rethrow
    }
}

// run action
function openpanelUserAction($params, $method, $payload = null) {
    $token = getOpenPanelAuthToken($params);
    if (!$token) return 'Authentication failed';

    $response = apiRequest(
        $params,
        '/api/users/' . $params['username'],
        $token,
        $method,
        $payload
    );

    return ($response['success'] ?? false)
        ? 'success'
        : ($response['error'] ?? 'Unknown error');
}



# ======================================================================
# Main Functions

// CREATE ACCOUNT
function openpanel_CreateAccount($params) {
    $token = getOpenPanelAuthToken($params);
    if (!$token) return 'Authentication failed';

    $product = mysql_fetch_array(
        select_query('tblproducts', 'name', ['id' => $params['pid']])
    );

    return openpanelUserAction($params, 'POST', [
        'username' => $params['username'],
        'password' => $params['password'],
        'email'    => $params['clientsdetails']['email'],
        'plan_name' => $product['name'],
    ]);
}


// SUSPEND ACCOUNT
function openpanel_SuspendAccount($params) {
    return openpanelUserAction($params, 'PATCH', ['action' => 'suspend']);
}

// UNSUSPEND ACCOUNT
function openpanel_UnsuspendAccount($params) {
    return openpanelUserAction($params, 'PATCH', ['action' => 'unsuspend']);
}


// CHANGE PASSWORD
function openpanel_ChangePassword($params) {
    return openpanelUserAction($params, 'PATCH', [
        'password' => $params['password']
    ]);
}

// TERMINATE ACCOUNT
function openpanel_TerminateAccount($params) {
    openpanelUserAction($params, 'PATCH', ['action' => 'unsuspend']);
    return openpanelUserAction($params, 'DELETE');
}

// CHANGE PACKAGE
function openpanel_ChangePackage($params) {
    $product = mysql_fetch_array(
        select_query('tblproducts', 'name', ['id' => $params['pid']])
    );

    return openpanelUserAction($params, 'PUT', [
        'plan_name' => $product['name']
    ]);
}

// GENERATE LOGIN LINK
function openpanelGenerateLoginLink($params) {
    $token = getOpenPanelAuthToken($params);
    if (!$token) {
        return [null, 'Authentication failed'];
    }

    $response = apiRequest(
        $params,
        '/api/users/' . $params['username'],
        $token,
        'CONNECT'
    );

    return isset($response['link'])
        ? [$response['link'], null]
        : [null, $response['message'] ?? 'Unable to generate login link'];
}

function openpanelLoginButtonHtml($link) {
    return '
<script>
function loginOpenPanelButton() {
    document.getElementById("loginLink").textContent = "Logging in...";
    document.getElementById("refreshMessage").style.display = "block";
    document.getElementById("loginLink").style.display = "none";
}
</script>

<a id="loginLink" class="btn btn-primary"  style="display:block;" href="' . htmlspecialchars($link) . '" target="_blank"
   onclick="loginOpenPanelButton()">
    Login to OpenPanel
</a>

<p id="refreshMessage" style="display:none;">
    One-time login link has already been used, please refresh the page to login again.
</p>';
}

// CLIENT AREA
function openpanel_ClientArea($params) {
    list($link, $error) = openpanelGenerateLoginLink($params);

    return $link
        ? openpanelLoginButtonHtml($link)
        : '<p>Error: ' . htmlentities($error) . '</p>';
}


// ADMIN LINK
function openpanel_AdminLink($params) {
    $url = openpanelBaseUrl($params) . '/login';

    return '
<form action="' . $url . '" method="post" target="_blank">
    <input type="hidden" name="username" value="' . htmlspecialchars($params['serverusername']) . '">
    <input type="hidden" name="password" value="' . htmlspecialchars($params['serverpassword']) . '">
    <input type="submit" value="Login to OpenAdmin">
</form>';
}

// LOGIN LINK
function openpanel_LoginLink($params) {
    return openpanel_ClientArea($params);
}

// AVAILABLE PLANS
function getAvailablePlans($params) {
    $token = getOpenPanelAuthToken($params);
    if (!$token) {
        return 'Authentication failed';
    }

    $response = apiRequest(
        $params,
        '/api/plans',
        $token,
        'GET'
    );

    return $response['plans'] ?? 'Invalid plans response';
}

// CONFIG OPTIONS
function openpanel_ConfigOptions() {
    $productId = (int)($_REQUEST['id'] ?? 0);
    if (!$productId) {
        return ['Note' => ['Description' => 'Please save the product first.']];
    }

    $product = mysql_fetch_array(
        select_query('tblproducts', 'servergroup', ['id' => $productId])
    );

    if (!$product['servergroup']) {
        return ['Note' => ['Description' => 'Assign a server group first.']];
    }

    $server = mysql_fetch_array(
        select_query('tblservers', '*', ['disabled' => 0])
    );

    if (!$server) {
        return ['Note' => ['Description' => 'No servers available.']];
    }

    $plans = getAvailablePlans([
        'hostname' => $server['hostname'],
        'username' => $server['username'],
        'password' => decrypt($server['password']),
    ]);

    if (!is_array($plans)) {
        return ['Note' => ['Description' => $plans]];
    }

    $options = [];
    foreach ($plans as $plan) {
        $options[$plan['id']] = $plan['name'];
    }

    return [
        'Plan' => [
            'Type' => 'dropdown',
            'Options' => $options,
            'Description' => 'Select a plan from OpenPanel',
        ],
    ];
}

// USAGE UPDATE
function openpanel_UsageUpdate($params) {
    $token = getOpenPanelAuthToken($params);
    if (!$token) {
        return json_encode(['success' => false, 'message' => 'Auth failed']);
    }

    $usage = apiRequest(
        $params,
        '/api/usage/disk',
        $token,
        'GET'
    );

    foreach ($usage as $user => $values) {
        update_query('tblhosting', [
            'diskusage' => $values['disk_usage'],
            'disklimit' => $values['disk_limit'],
            'lastupdate' => 'now()',
        ], [
            'server' => $params['serverid'],
            'username' => $user,
        ]);
    }

    return json_encode(['success' => true]);
}

?>
