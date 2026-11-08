<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * MODULE-NAME-01: the paneldns-reseller / paneldns_reseller → paneldnsreseller migration.
 *
 * @covers \PanelDnsResellerMigration
 */
final class PanelDnsResellerMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        Capsule::reset();
        PanelDnsResellerMigration::resetForTests();
        $GLOBALS['__activity_log'] = [];
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        PanelDnsResellerMigration::resetForTests();
    }

    public function test_sql_builder_covers_both_legacy_names_on_products_and_servers(): void
    {
        $this->assertSame([
            "UPDATE tblproducts SET servertype = 'paneldnsreseller' WHERE servertype IN ('paneldns-reseller', 'paneldns_reseller');",
            "UPDATE tblservers SET type = 'paneldnsreseller' WHERE type IN ('paneldns-reseller', 'paneldns_reseller');",
        ], PanelDnsResellerMigration::sqlStatements());
    }

    public function test_the_upgrade_docs_carry_the_exact_sql(): void
    {
        $install = (string) file_get_contents(__DIR__ . '/../INSTALL.md');
        foreach (PanelDnsResellerMigration::sqlStatements() as $sql) {
            $this->assertStringContainsString($sql, $install);
        }
        $this->assertStringContainsString('modules/servers/paneldns-reseller/', $install, 'INSTALL.md tells you to delete the old folder');
    }

    public function test_new_name_is_a_valid_whmcs_module_name_and_used_everywhere(): void
    {
        // WHMCS: a single word of lowercase letters and numbers, starting with a letter.
        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9]*$/', PanelDnsResellerMigration::MODULE);
        $this->assertSame(PanelDnsResellerMigration::MODULE, PanelDnsResellerService::MODULE);
        $this->assertSame(PanelDnsResellerMigration::MODULE, PanelDnsResellerHooks::MODULE);
        $this->assertSame(PanelDnsResellerMigration::MODULE, PanelDnsResellerDriftSync::MODULE_RESELLER);

        $dir = __DIR__ . '/../modules/servers/' . PanelDnsResellerMigration::MODULE;
        $this->assertFileExists($dir . '/' . PanelDnsResellerMigration::MODULE . '.php');
        $this->assertTrue(function_exists('paneldnsreseller_CreateAccount') || str_contains(
            (string) file_get_contents($dir . '/paneldnsreseller.php'),
            'function paneldnsreseller_CreateAccount('
        ));
        $this->assertDirectoryDoesNotExist(__DIR__ . '/../modules/servers/paneldns-reseller');
    }

    public function test_run_rewrites_both_tables_through_capsule(): void
    {
        Capsule::$handler = fn (FakeQuery $q, string $op) => $op === 'update' ? ($q->baseTable() === 'tblproducts' ? 2 : 1) : null;

        $this->assertSame(['tblproducts' => 2, 'tblservers' => 1], PanelDnsResellerMigration::run());

        $products = Capsule::ops('update', 'tblproducts')[0];
        $this->assertTrue($products->hasWhere('servertype', 'in', ['paneldns-reseller', 'paneldns_reseller']));
        $this->assertSame(['servertype' => 'paneldnsreseller'], $products->arg);

        $servers = Capsule::ops('update', 'tblservers')[0];
        $this->assertTrue($servers->hasWhere('type', 'in', ['paneldns-reseller', 'paneldns_reseller']));
        $this->assertSame(['type' => 'paneldnsreseller'], $servers->arg);
    }

    public function test_run_if_pending_does_nothing_when_nothing_is_pending(): void
    {
        Capsule::$handler = fn (FakeQuery $q, string $op) => $op === 'exists' ? false : null;

        $this->assertSame([], PanelDnsResellerMigration::runIfPending());
        $this->assertCount(2, Capsule::ops('exists'), 'two cheap existence checks');
        $this->assertSame([], Capsule::ops('update'));
    }

    public function test_run_if_pending_migrates_and_logs_once_per_request(): void
    {
        Capsule::$handler = fn (FakeQuery $q, string $op) => match ($op) {
            'exists' => $q->baseTable() === 'tblservers',
            'update' => 1,
            default  => null,
        };

        $this->assertSame(['tblproducts' => 1, 'tblservers' => 1], PanelDnsResellerMigration::runIfPending());
        $this->assertStringContainsString('migrated module name to "paneldnsreseller"', implode("\n", $GLOBALS['__activity_log']));

        // Second call in the same request: no queries at all.
        $queries = count(Capsule::$queries);
        $this->assertSame([], PanelDnsResellerMigration::runIfPending());
        $this->assertCount($queries, Capsule::$queries);
    }

    public function test_run_if_pending_never_throws(): void
    {
        Capsule::$handler = function () { throw new \RuntimeException('SQLSTATE[42S02]: table missing'); };

        $this->assertSame([], PanelDnsResellerMigration::runIfPending());
        $log = implode("\n", $GLOBALS['__activity_log']);
        $this->assertStringContainsString('RuntimeException', $log);
        $this->assertStringNotContainsString('SQLSTATE', $log, 'class only, no SQL in the activity log');
    }

    public function test_hooks_register_the_migration_on_admin_pages_and_the_daily_cron(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../modules/servers/paneldnsreseller/hooks.php');
        $this->assertMatchesRegularExpression("/add_hook\('AdminAreaPage'.*?runIfPending\(\)/s", $src);
        $this->assertMatchesRegularExpression("/add_hook\('DailyCronJob', 1, function \(\) \{\s*\/\/[^\n]*\n\s*PanelDnsResellerMigration::runIfPending\(\);/", $src);
    }
}
