<?php
/**
 * Repro #20585 — SQLite3::createAggregate / loadExtension (php-src-strict).
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20585_sqlite3_agg.php
 */
echo 'createAggregate=', method_exists('SQLite3', 'createAggregate') ? 'yes' : 'no', "\n";
echo 'loadExtension=', method_exists('SQLite3', 'loadExtension') ? 'yes' : 'no', "\n";

$db = new SQLite3(':memory:');
$db->exec('CREATE TABLE t(n INTEGER)');
$db->exec('INSERT INTO t VALUES (1),(2),(3)');
$db->createAggregate(
    'mysum',
    static function ($ctx, $rownum, $n) {
        if (null === $ctx) {
            $ctx = 0;
        }

        return (int) $ctx + (int) $n;
    },
    static function ($ctx, $rownum) {
        return (int) $ctx;
    },
    1
);
$sum = $db->querySingle('SELECT mysum(n) FROM t');
echo 'sum=', var_export($sum, true), "\n";
echo ($sum === 6) ? "OK\n" : "FAIL\n";
$db->close();
