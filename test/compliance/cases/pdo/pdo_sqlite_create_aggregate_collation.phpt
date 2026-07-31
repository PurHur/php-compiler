--TEST--
PDO::sqliteCreateAggregate + sqliteCreateCollation (#22332, ext/pdo_sqlite)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
--FILE--
<?php
$pdo = new PDO('sqlite::memory:');
echo 'has_agg=', method_exists($pdo, 'sqliteCreateAggregate') ? 'yes' : 'no', "\n";
echo 'has_col=', method_exists($pdo, 'sqliteCreateCollation') ? 'yes' : 'no', "\n";

$reg = $pdo->sqliteCreateAggregate(
    'spsum',
    static function ($ctx, $rownum, $v) {
        return (null === $ctx ? 0 : (int) $ctx) + (int) $v;
    },
    static function ($ctx, $rownum) {
        return null === $ctx ? 0 : (int) $ctx;
    },
    1
);
echo 'agg_reg=', $reg ? '1' : '0', "\n";
$pdo->exec('CREATE TABLE t(x); INSERT INTO t VALUES (1),(2)');
echo 'sum=', $pdo->query('SELECT spsum(x) FROM t')->fetchColumn(), "\n";

$ok = $pdo->sqliteCreateCollation('rev', static function (string $a, string $b): int {
    return strcmp($b, $a);
});
echo 'col_reg=', $ok ? '1' : '0', "\n";
$pdo->exec("CREATE TABLE w(name TEXT); INSERT INTO w VALUES ('b'),('a'),('c')");
$st = $pdo->query('SELECT name FROM w ORDER BY name COLLATE rev');
$parts = [];
while (false !== ($v = $st->fetchColumn())) {
    $parts[] = $v;
}
echo 'sorted=', implode(',', $parts), "\n";
?>
--EXPECT--
has_agg=yes
has_col=yes
agg_reg=1
sum=3
col_reg=1
sorted=c,b,a
