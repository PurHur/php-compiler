--TEST--
ext/sqlite3 SQLite3Result::fetchAll + Stmt busy/explain (#20600, #27594, php-src sqlite3.c)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
$db = new SQLite3(':memory:');
$db->exec('CREATE TABLE t(id INTEGER, name TEXT); INSERT INTO t VALUES (1,"a"),(2,"b");');
$r = $db->query('SELECT id, name FROM t ORDER BY id');
echo 'fetchAll=', method_exists($r, 'fetchAll') ? '1' : '0', "\n";
$rows = $r->fetchAll(SQLITE3_ASSOC);
echo 'n=', is_array($rows) ? count($rows) : '0', "\n";
echo 'r0=', $rows[0]['name'], "\n";
echo 'r1=', $rows[1]['id'], "\n";
$st = $db->prepare('SELECT 1');
echo 'busy=', method_exists($st, 'busy') ? '1' : '0', "\n";
echo 'explain=', method_exists($st, 'explain') ? '1' : '0', "\n";
echo 'setExplain=', method_exists($st, 'setExplain') ? '1' : '0', "\n";
echo 'busy_val=', $st->busy() ? '1' : '0', "\n";
echo 'mode0=', SQLite3Stmt::EXPLAIN_MODE_PREPARED, "\n";
echo 'mode1=', SQLite3Stmt::EXPLAIN_MODE_EXPLAIN, "\n";
echo 'mode2=', SQLite3Stmt::EXPLAIN_MODE_EXPLAIN_QUERY_PLAN, "\n";
try {
    $st->setExplain(99);
    echo "bad=ok\n";
} catch (ValueError $e) {
    echo "bad=ValueError\n";
} catch (Error $e) {
    // Host SQLite < 3.43: explain APIs unavailable (php-src Apple-style Error).
    echo "bad=Error\n";
}
?>
--EXPECT--
fetchAll=1
n=2
r0=a
r1=2
busy=1
explain=1
setExplain=1
busy_val=0
mode0=0
mode1=1
mode2=2
bad=ValueError
