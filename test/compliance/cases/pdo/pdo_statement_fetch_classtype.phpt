--TEST--
PDOStatement::fetch/fetchAll honor FETCH_CLASS|FETCH_CLASSTYPE (#25667, ext/pdo/pdo_stmt.c)
--FILE--
<?php
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

$st = $pdo->query('SELECT "RowB" AS cls, "c" AS v');
$st->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_CLASSTYPE);
$viaSet = $st->fetch();
echo 'setFetchMode_CLASSTYPE=', get_class($viaSet), ':', var_export($viaSet->v, true), "\n";
?>
--EXPECT--
fetchAll_CLASSTYPE=RowA:'ctor',RowB:'b'
fetch_CLASSTYPE=RowA:'ctor'
missing_class=stdClass:'z'
setFetchMode_CLASSTYPE=RowB:'c'
