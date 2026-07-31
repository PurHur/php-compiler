--TEST--
PDOStatement::fetch/fetchAll honor FETCH_CLASS / FETCH_INTO / FETCH_FUNC (#25641, ext/pdo/pdo_stmt.c)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
--FILE--
<?php
class Row
{
    public $n;
    public $m;
    public function __construct()
    {
        echo 'ctor:', $this->n ?? '?', "\n";
    }
}

class RowLate
{
    public $n = 'def';
    public $m;
    public function __construct()
    {
        echo 'late:', $this->n, "\n";
    }
}

function pdo_fmt($n, $m)
{
    return $n.'='.$m;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE t(n TEXT, m INT)');
$pdo->exec('INSERT INTO t VALUES("a",1),("b",2)');

$st = $pdo->query('SELECT n, m FROM t');
$st->setFetchMode(PDO::FETCH_CLASS, 'Row');
$r = $st->fetch();
echo 'fetch_CLASS=', get_class($r), ':', $r->n, ':', $r->m, "\n";

$all = $pdo->query('SELECT n, m FROM t')->fetchAll(PDO::FETCH_CLASS, 'Row');
echo 'fetchAll_CLASS0=', get_class($all[0]), ':', $all[0]->n, "\n";
echo 'fetchAll_CLASS1=', get_class($all[1]), ':', $all[1]->n, "\n";

$fo = $pdo->query('SELECT n, m FROM t')->fetchObject('Row');
echo 'fetchObject=', get_class($fo), ':', $fo->n, "\n";

$into = new stdClass();
$st = $pdo->query('SELECT n, m FROM t');
$st->setFetchMode(PDO::FETCH_INTO, $into);
$r = $st->fetch();
echo 'fetch_INTO_same=', ($r === $into ? 'yes' : 'no'), ' n=', $into->n, "\n";

$fn = $pdo->query('SELECT n, m FROM t')->fetchAll(PDO::FETCH_FUNC, 'pdo_fmt');
echo 'fetchAll_FUNC=', var_export($fn, true), "\n";

$st = $pdo->query('SELECT n, m FROM t');
$st->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, 'RowLate');
$late = $st->fetch();
echo 'PROPS_LATE_final=', $late->n, "\n";

$std = $pdo->query('SELECT n, m FROM t')->fetchAll(PDO::FETCH_CLASS);
echo 'fetchAll_CLASS_default=', get_class($std[0]), ':', $std[0]->n, "\n";

try {
    $pdo->query('SELECT n FROM t')->fetch(PDO::FETCH_CLASS);
    echo "bare_CLASS=no_throw\n";
} catch (PDOException $e) {
    echo 'bare_CLASS=', (str_contains($e->getMessage(), 'No fetch class') ? 'ok' : $e->getMessage()), "\n";
}
?>
--EXPECT--
ctor:a
fetch_CLASS=Row:a:1
ctor:a
ctor:b
fetchAll_CLASS0=Row:a
fetchAll_CLASS1=Row:b
ctor:a
fetchObject=Row:a
fetch_INTO_same=yes n=a
fetchAll_FUNC=array (
  0 => 'a=1',
  1 => 'b=2',
)
late:def
PROPS_LATE_final=a
fetchAll_CLASS_default=stdClass:a
bare_CLASS=ok
