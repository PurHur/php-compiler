<?php
// Repro for #25641 — PDO FETCH_CLASS / INTO / FUNC (php-src ext/pdo/pdo_stmt.c).
class Row
{
    public $n;
    public $m;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE t(n TEXT, m INT)');
$pdo->exec('INSERT INTO t VALUES("a",1)');

$st = $pdo->query('SELECT n, m FROM t');
$st->setFetchMode(PDO::FETCH_CLASS, 'Row');
$r = $st->fetch();
echo get_class($r), ':', $r->n, ':', $r->m, "\n";

$into = new stdClass();
$st = $pdo->query('SELECT n, m FROM t');
$st->setFetchMode(PDO::FETCH_INTO, $into);
$st->fetch();
echo 'into:', $into->n, "\n";

function fmt25641($n, $m)
{
    return $n.'='.$m;
}
$fn = $pdo->query('SELECT n, m FROM t')->fetchAll(PDO::FETCH_FUNC, 'fmt25641');
echo 'func:', $fn[0], "\n";
