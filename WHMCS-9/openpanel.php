<?php
################################################################################
# Name: OpenPanel WHMCS Module (WHMCS 9.x)
# Usage: https://openpanel.com/docs/articles/extensions/openpanel-and-whmcs/
# Source: https://github.com/stefanpejcic/openpanel-whmcs-module
# Author: Stefan Pejcic
# Created: 01.05.2024
# Company: openpanel.com
# Copyright (c) Stefan Pejcic
#
# Requires WHMCS 9.0+ (PHP 8.2+). Install into modules/servers/openpanel/.
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

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/ApiException.php';
require_once __DIR__ . '/lib/ApiClient.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\OpenPanel\ApiClient;
use WHMCS\Module\Server\OpenPanel\ApiException;


# ======================================================================
# START Module definition

function openpanel_MetaData()
{
    return [
        'DisplayName' => 'OpenPanel',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '2087',
        'DefaultSSLPort' => '2087',
        'ServiceSingleSignOnLabel' => 'Log in to OpenPanel',
        'AdminSingleSignOnLabel' => 'Log in to OpenAdmin',
    ];
}

/*
    PRODUCT MODULE SETTINGS
    configoption1 holds the OpenPanel plan name. In simple mode WHMCS fills the
    dropdown from openpanel_PlanLoader(), using a server from the product's
    server group.
*/
function openpanel_ConfigOptions()
{
    return [
        'Plan' => [
            'FriendlyName' => 'OpenPanel Plan',
            'Type' => 'text',
            'Size' => '30',
            'Loader' => 'openpanel_PlanLoader',
            'SimpleMode' => true,
            'Description' => 'Plan to create accounts on. If empty, the plan with the same name as this product is used.',
        ],
    ];
}

function openpanel_PlanLoader(array $params)
{
    $list = [];
    foreach (openpanelFetchPlans(new ApiClient($params)) as $plan) {
        $name = (string) ($plan['name'] ?? '');
        if ($name !== '') {
            $list[$name] = $name;
        }
    }

    if (!$list) {
        throw new ApiException('The OpenPanel server has no plans. Create one in OpenAdmin first.');
    }

    return $list;
}

/*
    "Test Connection" button on the server configuration page
*/
function openpanel_TestConnection(array $params)
{
    try {
        $api = new ApiClient($params);
        $api->authenticate();
        openpanelFetchPlans($api);

        return ['success' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

# END Module definition
# ======================================================================
# START Account actions

/*
    CREATE ACCOUNT
*/
function openpanel_CreateAccount(array $params)
{
    $username = (string) ($params['username'] ?? '');

    // opencli user-add's own rule; checked here because its error text never reaches the API response
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9]{2,19}$/', $username)) {
        return 'OpenPanel usernames must be 3-20 characters, start with a letter and contain only letters and numbers. Change the service username and try again.';
    }

    try {
        $api = new ApiClient($params);

        // 1. create user
        $result = $api->call('POST', '/api/users', [
            'username' => $username,
            'password' => (string) ($params['password'] ?? ''),
            'email' => (string) ($params['clientsdetails']['email'] ?? ''),
            'plan_name' => openpanelPlanName($params, $api),
        ], 600);
        openpanelAssertSuccess($result);
    } catch (Throwable $e) {
        return 'Could not create the OpenPanel user: ' . $e->getMessage();
    }

    // 2. add the domain (OpenAdmin defaults the docroot to /var/www/html/<domain>)
    $domain = strtolower(trim((string) ($params['domain'] ?? '')));
    if ($domain !== '') {
        try {
            $result = $api->call('POST', '/api/domains/new', ['username' => $username, 'domain' => $domain], 300);
            if ((int) ($result['returncode'] ?? 1) !== 0) {
                throw new ApiException(trim((string) ($result['stderr'] ?? '')) ?: trim((string) ($result['stdout'] ?? '')) ?: 'unknown error');
            }
        } catch (Throwable $e) {
            return 'The OpenPanel user was created, but adding ' . $domain . ' failed: ' . $e->getMessage()
                . ' Add the domain in OpenPanel and set this service to Active; running Create again will fail because the user exists.';
        }
    }

    return 'success';
}

/*
    SUSPEND ACCOUNT
*/
function openpanel_SuspendAccount(array $params)
{
    try {
        $api = new ApiClient($params);
        $account = openpanelRequireUser($api, $params);
        if (openpanelIsSuspended($account)) {
            return 'success';
        }

        openpanelAssertSuccess($api->call('PATCH', openpanelUserUri($params), ['action' => 'suspend'], 300));
        return 'success';
    } catch (Throwable $e) {
        return 'Could not suspend the OpenPanel user: ' . $e->getMessage();
    }
}

/*
    UNSUSPEND ACCOUNT
    Skipped when the user is not suspended: opencli user-unsuspend finds the
    account with LIKE 'SUSPENDED_%<username>', which can also match another
    suspended user whose name ends in this one.
*/
function openpanel_UnsuspendAccount(array $params)
{
    try {
        $api = new ApiClient($params);
        $account = openpanelRequireUser($api, $params);
        if (!openpanelIsSuspended($account)) {
            return 'success';
        }

        openpanelAssertSuccess($api->call('PATCH', openpanelUserUri($params), ['action' => 'unsuspend'], 300));
        return 'success';
    } catch (Throwable $e) {
        return 'Could not unsuspend the OpenPanel user: ' . $e->getMessage();
    }
}

/*
    TERMINATE ACCOUNT
    opencli user-delete handles suspended users itself, so there is no
    unsuspend first. The existence check matters: given a username that does
    not exist, opencli's LIKE lookup can pick a different suspended user.
*/
function openpanel_TerminateAccount(array $params)
{
    try {
        $api = new ApiClient($params);
        openpanelRequireUser($api, $params);

        openpanelAssertSuccess($api->call('DELETE', openpanelUserUri($params), null, 600));
        return 'success';
    } catch (Throwable $e) {
        return 'Could not delete the OpenPanel user: ' . $e->getMessage();
    }
}

/*
    CHANGE PASSWORD
*/
function openpanel_ChangePassword(array $params)
{
    try {
        $api = new ApiClient($params);
        openpanelAssertSuccess($api->call('PATCH', openpanelUserUri($params), ['password' => (string) ($params['password'] ?? '')], 120));
        return 'success';
    } catch (Throwable $e) {
        return 'Could not change the OpenPanel password: ' . $e->getMessage();
    }
}

/*
    CHANGE PACKAGE
*/
function openpanel_ChangePackage(array $params)
{
    try {
        $api = new ApiClient($params);
        openpanelAssertSuccess($api->call('PUT', openpanelUserUri($params), ['plan_name' => openpanelPlanName($params, $api)], 300));
        return 'success';
    } catch (Throwable $e) {
        return 'Could not change the OpenPanel plan: ' . $e->getMessage();
    }
}

/*
    USAGE UPDATE (daily cron, once per server)
    OpenAdmin reports disk in KB (repquota blocks); WHMCS stores MB.
    Bandwidth is not tracked by OpenAdmin, so bwusage/bwlimit are left alone.
*/
function openpanel_UsageUpdate(array $params)
{
    try {
        $usage = (new ApiClient($params))->call('GET', '/api/usage/disk', null, 300);
    } catch (Throwable $e) {
        return 'Could not fetch usage from OpenPanel: ' . $e->getMessage();
    }

    foreach ($usage as $username => $values) {
        if (!is_array($values)) {
            continue;
        }

        Capsule::table('tblhosting')
            ->where('server', (int) $params['serverid'])
            ->where('username', (string) $username)
            ->update([
                'diskusage' => openpanelKbToMb($values['diskusage'] ?? 0),
                'disklimit' => openpanelKbToMb($values['disklimit'] ?? 0),
                'lastupdate' => Capsule::raw('NOW()'),
            ]);
    }

    return 'success';
}

# END Account actions
# ======================================================================
# START Single sign-on and client area

/*
    "Log in to OpenPanel": admin service page button, and the client area
    button in templates/clientarea.tpl
*/
function openpanel_ServiceSingleSignOn(array $params)
{
    try {
        $response = (new ApiClient($params))->call('POST', openpanelUserUri($params) . '/autologin', null, 60);
        if (!is_string($response['link'] ?? null) || $response['link'] === '') {
            throw new ApiException('OpenAdmin did not return a login link.');
        }

        return ['success' => true, 'redirectTo' => $response['link']];
    } catch (Throwable $e) {
        if (defined('ADMINAREA')) {
            return ['success' => false, 'errorMsg' => 'Could not log in to OpenPanel: ' . $e->getMessage()];
        }

        // clients get a generic message; the reason goes to the activity log
        logActivity('OpenPanel single sign-on failed for service #' . (int) ($params['serviceid'] ?? 0) . ': ' . $e->getMessage());
        return ['success' => false, 'errorMsg' => 'The control panel login is unavailable right now. Please try again shortly or contact support.'];
    }
}

/*
    "Log in to OpenAdmin" link on the Servers page
*/
function openpanel_AdminSingleSignOn(array $params)
{
    try {
        return ['success' => true, 'redirectTo' => (new ApiClient($params))->adminLoginUrl()];
    } catch (Throwable $e) {
        return ['success' => false, 'errorMsg' => 'Could not log in to OpenAdmin: ' . $e->getMessage()];
    }
}

/*
    CLIENT AREA
    The login link is created only when the client clicks, so page views
    cost no API calls and a one-time link can never be stale.
*/
function openpanel_ClientArea(array $params)
{
    return [
        'templatefile' => 'templates/clientarea',
        'vars' => [
            'openpanelLoginUrl' => 'clientarea.php?action=productdetails&id=' . (int) $params['serviceid'] . '&dosinglesignon=1',
        ],
    ];
}

/*
    SERVICES TAB IN ADMIN AREA
*/
function openpanel_AdminServicesTabFields(array $params)
{
    $label = 'OpenPanel Account';
    if ((string) ($params['username'] ?? '') === '') {
        return [];
    }

    try {
        // short timeout: this runs on every load of the admin service page
        $account = openpanelFetchUser(new ApiClient($params), (string) $params['username'], 15);
    } catch (Throwable $e) {
        return [$label => '<span class="label label-danger">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</span>'];
    }

    if ($account === null) {
        return [$label => '<span class="label label-warning">User does not exist on this OpenPanel server</span>'];
    }

    return [$label => openpanelRenderView(__DIR__ . '/templates/admin_services_tab.php', openpanelAccountView($account))];
}

# END Single sign-on and client area
# ======================================================================
# START Helpers

function openpanelUserUri(array $params): string
{
    $username = (string) ($params['username'] ?? '');
    if ($username === '') {
        throw new ApiException('This service has no username.');
    }

    return '/api/users/' . rawurlencode($username);
}

/*
    OpenAdmin answers user actions with {"success": true, "response": {...}}
    or {"success": false, "error": "..."}.
*/
function openpanelAssertSuccess(array $result): void
{
    if (($result['success'] ?? false) !== true) {
        $error = $result['error'] ?? $result['message'] ?? '';
        throw new ApiException(is_string($error) && trim($error) !== '' ? trim($error) : 'OpenAdmin did not confirm the change.');
    }
}

/*
    GET /api/users/{username}, or null when the account does not exist.
    OpenAdmin also matches suspended accounts (renamed to
    SUSPENDED_<timestamp>_<username>) through a loose LIKE that can return a
    different user, so the username in the answer is checked before use.
*/
function openpanelFetchUser(ApiClient $api, string $username, int $timeout = 60): ?array
{
    try {
        $response = $api->call('GET', openpanelUserUri(['username' => $username]), null, $timeout);
    } catch (ApiException $e) {
        if ($e->getCode() === 404) {
            return null;
        }
        throw $e;
    }

    $account = $response['user'] ?? null;
    if (!is_array($account)) {
        return null;
    }

    $found = (string) ($account['user']['username'] ?? '');
    $isSameUser = strcasecmp($found, $username) === 0
        || preg_match('/^SUSPENDED_\d+_' . preg_quote($username, '/') . '$/i', $found) === 1;

    return $isSameUser ? $account : null;
}

function openpanelRequireUser(ApiClient $api, array $params): array
{
    $account = openpanelFetchUser($api, (string) ($params['username'] ?? ''));
    if ($account === null) {
        throw new ApiException('User ' . ($params['username'] ?? '') . ' does not exist on this OpenPanel server.');
    }

    return $account;
}

function openpanelIsSuspended(array $account): bool
{
    return strncmp((string) ($account['user']['username'] ?? ''), 'SUSPENDED_', 10) === 0;
}

function openpanelFetchPlans(ApiClient $api): array
{
    $plans = $api->call('GET', '/api/plans', null, 30)['plans'] ?? null;
    if (!is_array($plans)) {
        throw new ApiException('OpenAdmin returned an unexpected plan list.');
    }

    return $plans;
}

/*
    Plan name for create/change package: the Plan module setting, falling
    back to the plan named after the WHMCS product (v1 behaviour). v1 saved
    the plan ID in the setting, so a numeric value that is not itself a plan
    name is looked up as an ID.
*/
function openpanelPlanName(array $params, ApiClient $api): string
{
    $plan = trim((string) ($params['configoption1'] ?? ''));

    if ($plan === '') {
        $plan = trim((string) Capsule::table('tblproducts')->where('id', (int) ($params['pid'] ?? 0))->value('name'));
    } elseif (ctype_digit($plan)) {
        $plans = openpanelFetchPlans($api);
        if (!in_array($plan, array_map('strval', array_column($plans, 'name')), true)) {
            foreach ($plans as $candidate) {
                if ((string) ($candidate['id'] ?? '') === $plan) {
                    $plan = (string) ($candidate['name'] ?? '');
                    break;
                }
            }
        }
    }

    if ($plan === '') {
        throw new ApiException('No OpenPanel plan is set for this product (Module Settings > OpenPanel Plan).');
    }

    return $plan;
}

function openpanelKbToMb($kb): int
{
    return (int) round(max(0, (float) $kb) / 1024);
}

function openpanelHumanKb($kb): string
{
    $kb = max(0, (float) $kb);
    // OpenPanel counts 1 GB as 1,024,000 KB (opencli's GB_TO_BLOCKS), so a "5 GB" plan reads 5 GB here too
    if ($kb >= 1024000) {
        return round($kb / 1024000, 2) . ' GB';
    }
    if ($kb >= 1024) {
        return round($kb / 1024, 1) . ' MB';
    }

    return round($kb) . ' KB';
}

/*
    Display string for a value from the API: '—' for missing or non-scalar.
*/
function openpanelDisplay($value): string
{
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }

    return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : '—';
}

/*
    Turns the GET /api/users/{username} payload into display-ready strings
    for templates/admin_services_tab.php.
*/
function openpanelAccountView(array $account): array
{
    $user = is_array($account['user'] ?? null) ? $account['user'] : [];
    $plan = is_array($account['plan'] ?? null) ? $account['plan'] : [];
    $disk = is_array($account['disk_usage'] ?? null) ? $account['disk_usage'] : [];
    $sites = is_array($account['sites'] ?? null) ? $account['sites'] : [];

    $usage = [];
    if ($disk) {
        foreach ([['Disk', 'disk_used', 'disk_hard', true], ['Inodes', 'inodes_used', 'inodes_hard', false]] as [$name, $usedKey, $hardKey, $isKb]) {
            $used = (float) ($disk[$usedKey] ?? 0);
            $hard = (float) ($disk[$hardKey] ?? 0);
            $usage[] = [
                'label' => $name,
                'used' => $isKb ? openpanelHumanKb($used) : number_format($used),
                'limit' => $hard > 0 ? ($isKb ? openpanelHumanKb($hard) : number_format($hard)) : 'Unlimited',
                'percent' => $hard > 0 ? min(100, round($used / $hard * 100, 1)) : null,
            ];
        }
    }

    $domains = [];
    foreach ((array) ($account['domains'] ?? []) as $domain) {
        if (!is_array($domain)) {
            continue;
        }
        $domainSites = [];
        foreach ($sites as $site) {
            if (is_array($site) && (string) ($site['domain_id'] ?? '') === (string) ($domain['domain_id'] ?? '')) {
                $domainSites[] = [
                    'name' => openpanelDisplay($site['site_name'] ?? null),
                    'type' => openpanelDisplay($site['type'] ?? null),
                    'admin_email' => openpanelDisplay($site['admin_email'] ?? null),
                    'created' => openpanelDisplay($site['created_date'] ?? null),
                ];
            }
        }
        $domains[] = [
            'url' => openpanelDisplay($domain['domain_url'] ?? null),
            'php_version' => openpanelDisplay($domain['php_version'] ?? null),
            'docroot' => openpanelDisplay($domain['docroot'] ?? null),
            'sites' => $domainSites,
        ];
    }

    $planFields = [
        'name' => 'Name', 'description' => 'Description', 'disk_limit' => 'Disk Limit', 'inodes_limit' => 'Inodes Limit',
        'domains_limit' => 'Domains Limit', 'websites_limit' => 'Websites Limit', 'db_limit' => 'Database Limit',
        'email_limit' => 'Email Limit', 'ftp_limit' => 'FTP Limit', 'cpu' => 'CPU', 'ram' => 'RAM',
        'bandwidth' => 'Bandwidth', 'feature_set' => 'Feature Set',
    ];
    $planRows = [];
    foreach ($planFields as $key => $label) {
        $planRows[$label] = openpanelDisplay($plan[$key] ?? null);
    }

    return [
        'usage' => $usage,
        'domains' => $domains,
        'siteCount' => count($sites),
        'planRows' => $plan ? $planRows : [],
        'account' => [
            'Email' => openpanelDisplay($user['email'] ?? null),
            'Status' => openpanelIsSuspended($account) ? 'Suspended' : 'Active',
            'Docker context' => openpanelDisplay($user['server'] ?? null),
            'Owned by reseller' => openpanelDisplay($user['owner'] ?? null),
            '2FA enabled' => !empty($user['twofa_enabled']) ? 'Yes' : 'No',
            'Created' => openpanelDisplay($user['registered_date'] ?? null),
        ],
    ];
}

/*
    Renders a plain PHP view. Views escape everything with $e(): site names
    and admin e-mails are set by the hosting customer, and this HTML is shown
    to WHMCS admins.
*/
function openpanelRenderView(string $file, array $vars): string
{
    $e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    extract($vars, EXTR_SKIP);

    ob_start();
    try {
        include $file;
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
}

# END Helpers
# ======================================================================
