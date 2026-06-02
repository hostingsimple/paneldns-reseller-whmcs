<?php

/**
 * paneldns-reseller — WHMCS server module for selling sub-client DNS hosting.
 *
 * Drives the reseller-tier Public API (/api/v1). Used by a reseller who has
 * a PanelDNS org and wants to sell DNS to their own customers via WHMCS:
 *   - WHMCS Create → POST /api/v1/sub-clients
 *   - WHMCS Suspend → PATCH /api/v1/sub-clients/{id} {status: suspended}
 *   - WHMCS Unsuspend → PATCH … {status: active}
 *   - WHMCS Terminate → DELETE /api/v1/sub-clients/{id}
 *   - WHMCS ChangePackage → PATCH … {zone_limit, max_records}
 *   - "Login to PanelDNS Portal" → POST /api/v1/sub-clients/{id}/sso-token → 302
 *
 * Authentication: a per-user Sanctum Bearer token issued by the reseller's
 * own PanelDNS dashboard (Dashboard → API Tokens). Scopes required:
 * sub_clients:write, sub_clients:read.
 *
 * See INSTALL.md in the repo root for setup.
 *
 * @package paneldns-whmcs
 */

if (!defined('WHMCS')) {
    die('Access denied.');
}

/**
 * Module version. Surfaced in AdminServicesTabFields() so the operator can
 * see at a glance which module build is installed against each WHMCS service.
 * Bump this in lockstep with the repo release tag.
 */
if (!defined('PANELDNS_RESELLER_MODULE_VERSION')) {
    define('PANELDNS_RESELLER_MODULE_VERSION', '1.4.0');
}

require_once __DIR__ . '/lib/PanelDnsResellerService.php';
require_once __DIR__ . '/lib/PanelDnsApi.php';

function paneldns_reseller_MetaData(): array
{
    return [
        'DisplayName'                              => 'PanelDNS (Reseller — Sub-client DNS Hosting)',
        'APIVersion'                               => '1.1',
        'RequiresServer'                           => true,
        // Adds a "Login to PanelDNS as Client" link button in the admin
        // service detail page that redirects the admin's browser to the
        // sub-client's authenticated portal session via SSO.
        'AdminSingleSignOnLabel'                   => 'Login to PanelDNS as Client',
        // Adds "Login to PanelDNS" to the client's service page in the
        // WHMCS client area, outside the module's own template.
        'ServiceSingleSignOnLabel'                 => 'Login to PanelDNS',
        'ListAccountsUniqueIdentifierDisplayName'  => 'PanelDNS Sub-client ID',
        // Sub-client IDs are stored in tblhosting.dedicatedip — this tells
        // WHMCS which service field to compare against ListAccounts results.
        'ListAccountsUniqueIdentifierField'        => 'dedicatedip',
        // FIX-M18: ListAccountsProductField tells WHMCS which config option
        // holds the identifying product field for the Sync tool. configoption1
        // is Zone Limit — the primary plan discriminator for this module.
        'ListAccountsProductField'                 => 'configoption1',
    ];
}

function paneldns_reseller_ConfigOptions(): array
{
    return [
        'Zone Limit' => [
            'Type'        => 'text',
            'Size'        => 6,
            'Default'     => '5',
            'Description' => 'Maximum zones this sub-client can create. 0 = inherit org plan limit.',
        ],
        'Max Records Per Zone' => [
            'Type'        => 'text',
            'Size'        => 6,
            'Default'     => '100',
            'Description' => 'Maximum DNS records per zone. 0 = inherit org plan limit.',
        ],
        'Send Welcome Email' => [
            'Type'        => 'yesno',
            'Default'     => 'yes',
            'Description' => 'On CreateAccount, mint a 60-second portal SSO link and email it to the customer.',
        ],
        // T2.3 — per-product NS overrides for the welcome email.
        // The sub-client inherits the parent org's NS records server-side;
        // these fields only change which NS hostnames appear in the welcome
        // email and client-area "use these nameservers" panel — useful when
        // a single reseller org sells under multiple WHMCS brands.
        'NS1 Hostname' => [
            'Type'        => 'text',
            'Size'        => 32,
            'Default'     => '',
            'Description' => 'Optional. Overrides the org-default NS hostname shown to this sub-client in the welcome email.',
        ],
        'NS2 Hostname' => [
            'Type'        => 'text',
            'Size'        => 32,
            'Default'     => '',
            'Description' => 'Second NS hostname for the welcome email.',
        ],
        'NS3 Hostname' => [
            'Type'        => 'text',
            'Size'        => 32,
            'Default'     => '',
            'Description' => 'Third NS hostname (optional).',
        ],
        'NS4 Hostname' => [
            'Type'        => 'text',
            'Size'        => 32,
            'Default'     => '',
            'Description' => 'Fourth NS hostname (optional).',
        ],
        'SOA Email' => [
            'Type'        => 'text',
            'Size'        => 32,
            'Default'     => '',
            'Description' => 'SOA contact email shown in the welcome email. Defaults to the org\'s configured value.',
        ],
        'Auto-Create Zone on Domain Order' => [
            'Type'        => 'yesno',
            'Default'     => 'yes',
            'Description' => 'When a domain is registered or transferred for this client, automatically create a matching DNS zone in PanelDNS.',
        ],
        'Auto-Delete Zone on Domain Expiry' => [
            'Type'        => 'yesno',
            'Default'     => 'no',
            'Description' => 'When a domain is deleted or expires, remove the DNS zone. Disabled by default — enable only if you are sure clients do not need the zone data.',
        ],
        // configoption11 — GRACE-01
        'Termination Grace Period (Days)' => [
            'Type'        => 'text',
            'Size'        => 4,
            'Default'     => '0',
            'Description' => 'Days to wait before permanently deleting the sub-client after a WHMCS termination. 0 = delete immediately (default). During the grace period the sub-client is suspended in PanelDNS and removed nightly once the period expires.',
        ],
    ];
}

function paneldns_reseller_CreateAccount(array $params): string
{
    return PanelDnsResellerService::run('createAccount', $params);
}

function paneldns_reseller_SuspendAccount(array $params): string
{
    return PanelDnsResellerService::run('suspendAccount', $params);
}

function paneldns_reseller_UnsuspendAccount(array $params): string
{
    return PanelDnsResellerService::run('unsuspendAccount', $params);
}

function paneldns_reseller_TerminateAccount(array $params): string
{
    return PanelDnsResellerService::run('terminateAccount', $params);
}

function paneldns_reseller_ChangePackage(array $params): string
{
    return PanelDnsResellerService::run('changePackage', $params);
}

function paneldns_reseller_AdminCustomButtonArray(): array
{
    return [
        'Test Connection'      => 'testConnection',
        'Resend Welcome Email' => 'resendWelcome',
        'Resync Status'        => 'resyncStatus',
        // "Login to PanelDNS as Client" is now handled by AdminSingleSignOn()
        // which provides a proper browser redirect rather than a custom button.
    ];
}

/**
 * Server-level buttons shown on the WHMCS Server configuration page
 * (Admin → Setup → Servers → Edit). These operate across all services
 * on this server rather than on a single service row.
 */
function paneldns_reseller_ServerCustomButtonArray(): array
{
    return [
        'Bulk Sync Sub-clients' => 'bulkSync',
    ];
}

/**
 * Bulk sync all Active/Suspended services on this server to PanelDNS
 * sub-clients. Idempotent: existing sub-clients (identified by email)
 * are linked rather than duplicated. Capped at 200 services per run.
 */
function paneldns_reseller_bulkSync(array $params): string
{
    return PanelDnsResellerService::bulkSyncForServer($params);
}

function paneldns_reseller_testConnection(array $params): string
{
    return PanelDnsResellerService::run('testConnection', $params);
}

function paneldns_reseller_resyncStatus(array $params): string
{
    return PanelDnsResellerService::run('resyncStatus', $params);
}

function paneldns_reseller_AdminServicesTabFields(array $params): array
{
    return PanelDnsResellerService::adminServicesTabFields($params);
}

/**
 * AdminSingleSignOn — WHMCS calls this when the admin clicks
 * "Login to PanelDNS as Client" in the service detail page.
 * Mints a 60-second SSO token and redirects the admin's browser
 * to the sub-client's authenticated portal session.
 *
 * @return array{success: bool, redirectUrl?: string, errorMsg?: string}
 */
function paneldns_reseller_AdminSingleSignOn(array $params): array
{
    return PanelDnsResellerService::adminSso($params);
}

/**
 * Re-send the welcome email with a fresh SSO link. Useful when the
 * original email was lost, expired, or the client was bulk-synced
 * without a welcome email being sent.
 */
function paneldns_reseller_resendWelcome(array $params): string
{
    return PanelDnsResellerService::run('resendWelcome', $params);
}

/**
 * ListAccounts — returns all sub-clients on this server so WHMCS can
 * cross-reference provisioned accounts against its service records and
 * surface any orphaned or mismatched services via the Sync tool.
 */
function paneldns_reseller_ListAccounts(array $params): array
{
    return PanelDnsResellerService::listAccounts($params);
}

/**
 * ServiceSingleSignOn — WHMCS calls this when the client clicks
 * "Login to PanelDNS" on their service page in the client area.
 * Works the same as AdminSingleSignOn but is triggered client-side.
 *
 * @return array{success: bool, redirectUrl?: string, errorMsg?: string}
 */
function paneldns_reseller_ServiceSingleSignOn(array $params): array
{
    return PanelDnsResellerService::clientSso($params);
}

/**
 * UsageUpdate — WHMCS calls this periodically to pull resource usage
 * into its usage graphs and reporting. Returns zone count as "disk"
 * and record count as "bandwidth" — the standard pattern for DNS modules.
 */
function paneldns_reseller_UsageUpdate(array $params): array
{
    return PanelDnsResellerService::usageUpdate($params);
}

function paneldns_reseller_ClientArea(array $params): array
{
    return PanelDnsResellerService::clientArea($params);
}

function paneldns_reseller_ClientAreaAllowedFunctions(): array
{
    return [
        // SSO redirect — handled by clientArea() sso action
        'sso',
        // T1.4 embedded DNS — page renders
        'zones', 'records', 'zone-create', 'zone-import',
        // T1.4 embedded DNS — mutations (form POST targets)
        'do-zone-create', 'do-zone-import', 'do-zone-delete',
        'do-record-create', 'do-record-update', 'do-record-delete',
    ];
}
