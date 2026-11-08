<?php

/**
 * PanelDnsResellerMigration — MODULE-NAME-01 (v1.9.3).
 *
 * Up to v1.9.2 the module lived in modules/servers/paneldns-reseller/ while its
 * functions were named paneldns_reseller_*. WHMCS stores the module FOLDER name in
 * tblproducts.servertype / tblservers.type and calls "{folder}_{Function}", so neither
 * spelling could work: a hyphen cannot appear in a PHP function name, and the
 * underscore spelling matched no folder. WHMCS's own rule ("Choosing a Name",
 * developers.whmcs.com/provisioning-modules/getting-started/) is a single word of
 * lowercase letters and numbers, so the module is now `paneldnsreseller`.
 *
 * This rewrites both legacy spellings to the new name, for products and servers.
 * It is idempotent (an UPDATE … WHERE … IN (legacy names) touches nothing on a second
 * run) and cheap to call: pending() is two existence checks against small tables.
 *
 * Run from hooks.php on AdminAreaPage and at the start of DailyCronJob. WHMCS only
 * loads a provisioning module's hooks.php after the module has been "activated"
 * (a product using it saved once), so INSTALL.md also gives the SQL by hand.
 */

if (!defined('WHMCS')) {
    die('Access denied.');
}

if (!class_exists('PanelDnsResellerMigration', false)) {
class PanelDnsResellerMigration
{
    /** The module name WHMCS must see — the folder name. */
    const MODULE = 'paneldnsreseller';

    /** Every name this module has been stored under before v1.9.3. */
    const LEGACY_NAMES = ['paneldns-reseller', 'paneldns_reseller'];

    /** table => column holding the module name. */
    const TARGETS = [
        'tblproducts' => 'servertype',
        'tblservers'  => 'type',
    ];

    /** @var bool once-per-request guard */
    private static $ran = false;

    /**
     * The migration as plain SQL — for INSTALL.md's manual path and for review. The
     * runtime path uses Capsule (run()), never these strings.
     *
     * @return string[]
     */
    public static function sqlStatements(): array
    {
        $legacy = implode(', ', array_map(fn ($n) => "'" . $n . "'", self::LEGACY_NAMES));
        $out = [];
        foreach (self::TARGETS as $table => $column) {
            $out[] = "UPDATE {$table} SET {$column} = '" . self::MODULE . "' WHERE {$column} IN ({$legacy});";
        }
        return $out;
    }

    /** True if any product or server still carries a legacy module name. */
    public static function pending(): bool
    {
        foreach (self::TARGETS as $table => $column) {
            if (\WHMCS\Database\Capsule::table($table)->whereIn($column, self::LEGACY_NAMES)->exists()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Rewrite every legacy name to the new one.
     *
     * @return array<string,int> table => rows updated
     */
    public static function run(): array
    {
        $updated = [];
        foreach (self::TARGETS as $table => $column) {
            $updated[$table] = (int) \WHMCS\Database\Capsule::table($table)
                ->whereIn($column, self::LEGACY_NAMES)
                ->update([$column => self::MODULE]);
        }
        return $updated;
    }

    /**
     * Hook entry point: at most once per request, only if something is pending, never
     * throws. Returns the per-table counts when it migrated something, else [].
     *
     * @return array<string,int>
     */
    public static function runIfPending(): array
    {
        if (self::$ran) {
            return [];
        }
        self::$ran = true;

        try {
            if (!self::pending()) {
                return [];
            }
            $updated = self::run();
            if (function_exists('logActivity') && array_sum($updated) > 0) {
                logActivity('PanelDNS Reseller: migrated module name to "' . self::MODULE . '" ('
                    . (int) ($updated['tblproducts'] ?? 0) . ' product(s), '
                    . (int) ($updated['tblservers'] ?? 0) . ' server(s)).');
            }
            return $updated;
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                // SEC-L06: class only — messages may carry SQL fragments.
                logActivity('PanelDNS Reseller: module-name migration failed (' . get_class($e) . ')');
            }
            return [];
        }
    }

    /** @internal test helper */
    public static function resetForTests(): void
    {
        self::$ran = false;
    }
}
}
