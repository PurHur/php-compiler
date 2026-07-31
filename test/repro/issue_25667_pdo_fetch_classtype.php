<?php
/** Repro #25667 — FETCH_CLASS|FETCH_CLASSTYPE uses first column as class (php-src pdo_stmt.c). */
class RowA
{
    public $v;

    public function __construct()
    {
        $this->v = 'ctor';
    }
}
class RowB
{
    public $v;
}

$pdo = new PDO('sqlite::memory:');
$all = $pdo->query('SELECT "RowA" AS cls, "a" AS v UNION ALL SELECT "RowB", "b"')
    ->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_CLASSTYPE);
echo 'fetchAll_CLASSTYPE=';
foreach ($all as $i => $o) {
    echo ($i ? ',' : ''), get_class($o), ':', var_export($o->v, true);
}
echo "\n";

$one = $pdo->query('SELECT "RowA" AS cls, "a" AS v')
    ->fetch(PDO::FETCH_CLASS | PDO::FETCH_CLASSTYPE);
echo 'fetch_CLASSTYPE=', get_class($one), ':', var_export($one->v, true), "\n";

$missing = $pdo->query('SELECT "NoSuchClass" AS cls, "z" AS v')
    ->fetch(PDO::FETCH_CLASS | PDO::FETCH_CLASSTYPE);
echo 'missing_class=', get_class($missing), ':', var_export($missing->v ?? null, true), "\n";
