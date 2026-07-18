<?php
/**
 * Maintainer parity probe for #20585.
 */
echo method_exists('SQLite3', 'createAggregate') ? '1' : '0';
echo method_exists('SQLite3', 'loadExtension') ? '1' : '0';
echo "\n";
$db = new SQLite3(':memory:');
$db->exec('CREATE TABLE t(n INT); INSERT INTO t VALUES (1),(2),(3);');
$db->createAggregate(
    'mysum',
    static function ($ctx, $rownum, $n) {
        return (null === $ctx ? 0 : (int) $ctx) + (int) $n;
    },
    static function ($ctx, $rownum) {
        return (int) $ctx;
    },
    1
);
echo $db->querySingle('SELECT mysum(n) FROM t'), "\n";
$db->close();
