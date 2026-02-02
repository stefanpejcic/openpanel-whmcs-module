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



// helper function used on AdminServicesTabFields 
function openpanelGetServerParams($params) {
    $server = mysql_fetch_array(
        select_query(
            'tblservers',
            '*',
            ['id' => (int) $params['serverid']]
        )
    );

    if (!$server) {
        throw new Exception('Server not found');
    }

    return [
        'serverhostname' => $server['hostname'],
        'serverport'     => $server['port'],
        'serverusername' => $server['username'],
        'serverpassword' => $server['password'],
    ];
}

function openpanel_AdminServicesTabFields($params) {
    $fields = [];

    try {
        $serverParams = openpanelGetServerParams($params);
        $apiParams = array_merge($params, $serverParams);

        $token = getOpenPanelAuthToken($apiParams);
        if (!$token) {
            $fields['API Status'] = 'Authentication failed (admin)';
            return $fields;
        }

        $response = apiRequest(
            $apiParams,
            '/api/users/' . $params['username'],
            $token,
            'GET'
        );

        if (empty($response['user'])) {
            $fields['API Status'] = 'User not found';
            return $fields;
        }

        $responseUser = $response['user'];

        $user    = $responseUser['user'] ?? [];
        $plan    = $responseUser['plan'] ?? [];
        $domains = $responseUser['domains'] ?? [];
        $sites   = $responseUser['sites'] ?? [];
        $disk    = $responseUser['disk_usage'] ?? [];

        /* =========================
         * User info
         * ========================= */
        $fields['Account Email'] = htmlspecialchars($user['email'] ?? '—');
        $fields['Owned by Reseller'] = !empty($user['owner'])
            ? htmlspecialchars($user['owner'])
            : '<span class="label label-default">No</span>';
        $fields['2FA Enabled'] = !empty($user['twofa_enabled'])
            ? '<span class="label label-success">Yes</span>'
            : '<span class="label label-default">No</span>';
        $fields['Registered'] = !empty($user['registered_date'])
            ? date('Y-m-d H:i', strtotime($user['registered_date']))
            : '—';
        $fields['Server'] = htmlspecialchars($user['server'] ?? '—');

        /* =========================
         * Disk usage
         * ========================= */
        if (!empty($disk)) {
            $fields['Disk Usage'] = '<table style="width:100%;border-collapse:collapse">'
                . '<tr><td><b>Disk Hard Limit</b></td><td>' . htmlspecialchars($disk['disk_hard'] ?? '—') . '</td></tr>'
                . '<tr><td><b>Disk Soft Limit</b></td><td>' . htmlspecialchars($disk['disk_soft'] ?? '—') . '</td></tr>'
                . '<tr><td><b>Disk Used</b></td><td>' . htmlspecialchars($disk['disk_used'] ?? '—') . '</td></tr>'
                . '<tr><td><b>Home Path</b></td><td>' . htmlspecialchars($disk['home_path'] ?? '—') . '</td></tr>'
                . '<tr><td><b>Inodes Hard Limit</b></td><td>' . htmlspecialchars($disk['inodes_hard'] ?? '—') . '</td></tr>'
                . '<tr><td><b>Inodes Soft Limit</b></td><td>' . htmlspecialchars($disk['inodes_soft'] ?? '—') . '</td></tr>'
                . '<tr><td><b>Inodes Used</b></td><td>' . htmlspecialchars($disk['inodes_used'] ?? '—') . '</td></tr>'
                . '</table>';
        }

        /* =========================
         * Plan info
         * ========================= */
        if (!empty($plan)) {
            $fields['Plan'] = '<table style="width:100%;border-collapse:collapse">'
                . '<tr><td><b>Name</b></td><td>' . htmlspecialchars($plan['name'] ?? '—') . '</td></tr>'
                . '<tr><td><b>Description</b></td><td>' . htmlspecialchars($plan['description'] ?? '—') . '</td></tr>'
                . '<tr><td><b>Domains Limit</b></td><td>' . ($plan['domains_limit'] ?: '∞') . '</td></tr>'
                . '<tr><td><b>Websites Limit</b></td><td>' . ($plan['websites_limit'] ?: '∞') . '</td></tr>'
                . '<tr><td><b>Disk Limit</b></td><td>' . htmlspecialchars($plan['disk_limit'] ?? '—') . '</td></tr>'
                . '<tr><td><b>CPU</b></td><td>' . htmlspecialchars($plan['cpu'] ?? '—') . '</td></tr>'
                . '<tr><td><b>RAM</b></td><td>' . htmlspecialchars($plan['ram'] ?? '—') . '</td></tr>'
                . '<tr><td><b>Bandwidth</b></td><td>' . htmlspecialchars($plan['bandwidth'] ?? '—') . '</td></tr>'
                . '<tr><td><b>DB Limit</b></td><td>' . htmlspecialchars($plan['db_limit'] ?? '—') . '</td></tr>'
                . '<tr><td><b>Email Limit</b></td><td>' . htmlspecialchars($plan['email_limit'] ?? '—') . '</td></tr>'
                . '<tr><td><b>FTP Limit</b></td><td>' . htmlspecialchars($plan['ftp_limit'] ?? '—') . '</td></tr>'
                . '<tr><td><b>Inodes Limit</b></td><td>' . htmlspecialchars($plan['inodes_limit'] ?? '—') . '</td></tr>'
                . '<tr><td><b>Feature Set</b></td><td>' . htmlspecialchars($plan['feature_set'] ?? '—') . '</td></tr>'
                . '</table>';
        } else {
            $fields['Plan'] = '—';
        }

        /* =========================
         * Domains & Sites
         * ========================= */
        if (!empty($domains)) {
            $domainHtml = '<ul style="margin:0;padding-left:18px">';
            foreach ($domains as $domain) {
                $domainHtml .= '<li>'
                    . '<b>' . htmlspecialchars($domain['domain_url']) . '</b> '
                    . '<small>(PHP ' . htmlspecialchars($domain['php_version'] ?? '—') . ')</small> '
                    . '<br><small>Docroot: ' . htmlspecialchars($domain['docroot'] ?? '—') . '</small>';

                $domainSites = array_filter($sites, fn($s) => ($s['domain_id'] ?? 0) == ($domain['domain_id'] ?? 0));
                if (!empty($domainSites)) {
                    $domainHtml .= '<ul style="margin:0;padding-left:18px">';
                    foreach ($domainSites as $site) {
                        $domainHtml .= '<li>'
                            . htmlspecialchars($site['site_name'] ?? '—')
                            . ' <small>(' . htmlspecialchars($site['type'] ?? '—')
                            . (!empty($site['version']) ? ' ' . htmlspecialchars($site['version']) : '')
                            . ', Admin: ' . htmlspecialchars($site['admin_email'] ?? '—')
                            . ', Created: ' . (!empty($site['created_date']) ? date('Y-m-d H:i', strtotime($site['created_date'])) : '—')
                            . ')</small>'
                            . '</li>';
                    }
                    $domainHtml .= '</ul>';
                }

                $domainHtml .= '</li>';
            }
            $domainHtml .= '</ul>';
            $fields['Domains & Sites (' . count($domains) . ')'] = $domainHtml;
        } else {
            $fields['Domains & Sites'] = 'None';
        }

    } catch (Exception $e) {
        $fields['API Status'] = 'Error: ' . htmlspecialchars($e->getMessage());
    }

    return $fields;
}








?>
