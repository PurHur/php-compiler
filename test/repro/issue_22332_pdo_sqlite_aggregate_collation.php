<?php
declare(strict_types=1);

/**
 * #22332 — PDO::sqliteCreateAggregate must register a callable UDF;
 * sqliteCreateCollation must exist and work with ORDER BY … COLLATE.
 */
$p = new PDO('sqlite::memory:');
$reg = $p->sqliteCreateAggregate(
    'spsum',
    static function ($c, $r, $v) {
        return ($c ?? 0) + $v;
    },
    static function ($c, $r) {
        return $c ?? 0;
    },
    1
);
echo 'reg=', $reg ? 'true' : 'false', "\n";
$p->exec('CREATE TABLE t(x); INSERT INTO t VALUES (1),(2)');
echo 'sum=', $p->query('SELECT spsum(x) FROM t')->fetchColumn(), "\n";
echo 'has_col=', method_exists($p, 'sqliteCreateCollation') ? 'yes' : 'no', "\n";
$p->sqliteCreateCollation('rev', static function (string $a, string $b): int {
    return strcmp($b, $a);
});
$p->exec("CREATE TABLE w(name TEXT); INSERT INTO w VALUES ('b'),('a'),('c')");
$st = $p->query('SELECT name FROM w ORDER BY name COLLATE rev');
$parts = [];
while (false !== ($v = $st->fetchColumn())) {
    $parts[] = $v;
}
echo 'sorted=', implode(',', $parts), "\n";
