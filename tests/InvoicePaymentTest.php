<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../modules/servers/paneldnsreseller/paneldnsreseller.php'; // paneldnsreseller_UnsuspendAccount

/**
 * PAY-02 (v1.9.5): paying an invoice never unsuspends anything through this module.
 *
 * Up to 1.9.4 hooks.php registered `InvoicePaymentSuccess` and, for any payment, set every
 * Suspended service of this module of that client to active in PanelDNS and Active in
 * WHMCS, whatever the invoice and whatever the suspension reason. WHMCS has no hook of
 * that name (https://developers.whmcs.com/hooks-reference/invoices-and-quotes/), and
 * WHMCS's own automation already unsuspends a service suspended for non-payment by
 * calling the module's UnsuspendAccount. The hook is gone, and none may be added.
 *
 * These tests pay an invoice by firing every handler hooks.php registers on any invoice
 * payment hook point, the old name included.
 *
 * @covers \PanelDnsResellerHooks
 * @covers \PanelDnsResellerService
 */
final class InvoicePaymentTest extends TestCase
{
    private const CLIENT  = 7;
    private const SERVICE = 500;
    private const SUB     = 42;
    private const INVOICE = 9001;

    /** The hook points WHMCS runs when an invoice is paid or a payment is applied, plus the 1.9.4 name. */
    private const PAYMENT_HOOKS = ['InvoicePaymentSuccess', 'InvoicePaid', 'InvoicePaidPreEmail', 'AddInvoicePayment'];

    protected function setUp(): void
    {
        Capsule::reset();
        PanelDnsResellerApi::resetRateLimitState();
        PanelDnsResellerHooks::$apiFactory = null;
        PanelDnsResellerService::$apiFactory = null;
        $GLOBALS['__activity_log'] = [];
        $GLOBALS['__local_api'] = [];
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        PanelDnsResellerHooks::$apiFactory = null;
        PanelDnsResellerService::$apiFactory = null;
    }

    /** hooks.php's registrations, loaded once through the add_hook() stub in bootstrap.php. */
    private static function hooks(): array
    {
        require_once __DIR__ . '/../modules/servers/paneldnsreseller/hooks.php';
        return $GLOBALS['__hooks'] ?? [];
    }

    /**
     * The client has one Suspended DNS service of this module, suspended for $reason, and
     * invoice INVOICE holds $items. Every query for the client's services returns it.
     */
    private function suspendedService(string $reason, array $items): FakeApi
    {
        $api = (new FakeApi())->on('PATCH', '/api/v1/sub-clients/' . self::SUB,
            FakeApi::json(200, ['ok' => true, 'data' => ['id' => self::SUB, 'status' => 'active']]));
        PanelDnsResellerHooks::$apiFactory = fn () => $api;

        $service = (object) [
            'id' => self::SERVICE, 'service_id' => self::SERVICE, 'userid' => self::CLIENT,
            'dedicatedip' => (string) self::SUB, 'sub_client_id' => (string) self::SUB,
            'domainstatus' => 'Suspended', 'suspendreason' => $reason,
            'server_hostname' => 'paneldns.test', 'server_key' => 'k', 'server_secure' => 1, 'server_port' => 443,
        ];
        $invoice = (object) ['id' => self::INVOICE, 'userid' => self::CLIENT, 'status' => 'Paid'];

        Capsule::$handler = function (FakeQuery $q, string $op) use ($service, $items, $invoice) {
            return match ($q->baseTable()) {
                'tblhosting'      => $op === 'get' ? [$service] : ($op === 'first' ? $service : null),
                'tblinvoiceitems' => $op === 'get' ? $items : null,
                'tblinvoices'     => $op === 'first' ? $invoice : null,
                default           => null,
            };
        };
        return $api;
    }

    private static function item(string $type, int $relid): object
    {
        return (object) ['invoiceid' => self::INVOICE, 'userid' => self::CLIENT, 'type' => $type, 'relid' => $relid];
    }

    /** WHMCS marks the invoice Paid: run every payment-hook handler hooks.php registered. */
    private function payInvoice(): void
    {
        $vars = ['invoiceid' => self::INVOICE, 'userid' => self::CLIENT, 'invoice' => null];
        foreach (self::PAYMENT_HOOKS as $point) {
            foreach (self::hooks()[$point] ?? [] as $fn) {
                $fn($vars);
            }
        }
    }

    private function assertNothingUnsuspended(FakeApi $api): void
    {
        $reactivated = array_filter(
            $api->callsTo('PATCH', '/api/v1/sub-clients/' . self::SUB),
            fn (array $call) => ($call['body']['status'] ?? null) === 'active'
        );
        $this->assertSame([], array_values($reactivated), 'the sub-client was re-activated in PanelDNS');

        $statusWrites = array_filter(
            Capsule::ops('update', 'tblhosting'),
            fn (FakeQuery $q) => array_key_exists('domainstatus', (array) $q->arg)
        );
        $this->assertSame([], array_values($statusWrites), 'the WHMCS service status was rewritten');

        $unsuspends = array_filter($GLOBALS['__local_api'] ?? [], fn (array $c) => $c[0] === 'ModuleUnsuspend');
        $this->assertSame([], array_values($unsuspends), 'ModuleUnsuspend was called');
    }

    // ── Paying an invoice ─────────────────────────────────────────────────────

    public function test_paying_an_unrelated_invoice_unsuspends_nothing(): void
    {
        // The DNS service is overdue, and the client pays a small Add Funds invoice.
        $api = $this->suspendedService('Overdue on Payment', [self::item('AddFunds', 0)]);

        $this->payInvoice();

        $this->assertNothingUnsuspended($api);
    }

    public function test_paying_the_invoice_of_another_service_unsuspends_nothing(): void
    {
        $api = $this->suspendedService('Overdue on Payment', [self::item('Hosting', self::SERVICE + 1)]);

        $this->payInvoice();

        $this->assertNothingUnsuspended($api);
    }

    /** @dataProvider nonPaymentReasonProvider */
    public function test_a_suspension_for_any_other_reason_survives_payment_of_the_services_own_invoice(string $reason): void
    {
        $api = $this->suspendedService($reason, [self::item('Hosting', self::SERVICE)]);

        $this->payInvoice();

        $this->assertNothingUnsuspended($api);
    }

    public static function nonPaymentReasonProvider(): array
    {
        return [
            'abuse'                => ['Abuse: phishing records'],
            'terms of service'     => ['Broken Terms of Service'],
            'ID check'             => ['Awaiting ID Verification'],
            'admin, no reason'     => [''],
        ];
    }

    public function test_no_handler_is_registered_on_any_invoice_payment_hook(): void
    {
        $hooks = self::hooks();
        foreach (self::PAYMENT_HOOKS as $point) {
            $this->assertArrayNotHasKey($point, $hooks, "hooks.php must not handle {$point} (PAY-02)");
        }
        $this->assertFalse(method_exists('PanelDnsResellerHooks', 'onInvoicePaid'));
    }

    // ── What WHMCS does instead ───────────────────────────────────────────────

    public function test_whmcs_auto_unsuspend_reactivates_the_sub_client_through_the_module(): void
    {
        // When an overdue invoice is paid and "Enable Unsuspension" is on, WHMCS unsuspends
        // a service it suspended for non-payment by calling the module's UnsuspendAccount.
        $api = (new FakeApi())->on('PATCH', '/api/v1/sub-clients/' . self::SUB,
            FakeApi::json(200, ['ok' => true, 'data' => ['id' => self::SUB, 'status' => 'active']]));
        PanelDnsResellerService::$apiFactory = fn () => $api;

        $result = paneldnsreseller_UnsuspendAccount([
            'serviceid'      => self::SERVICE,
            'userid'         => self::CLIENT,
            'serverhostname' => 'paneldns.test',
            'serversecure'   => true,
            'status'         => 'Suspended',
            'model'          => (object) ['id' => self::SERVICE, 'dedicatedip' => (string) self::SUB],
        ]);

        $this->assertSame('success', $result);
        $patch = $api->callsTo('PATCH', '/api/v1/sub-clients/' . self::SUB);
        $this->assertCount(1, $patch);
        $this->assertSame(['status' => 'active'], $patch[0]['body']);
    }
}
