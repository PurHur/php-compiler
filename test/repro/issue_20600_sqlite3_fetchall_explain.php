<?php
/**
 * Repro #20600 — SQLite3Result::fetchAll + SQLite3Stmt::busy/explain/setExplain
 * (php-src retarget: columnTypeName/fetchObject do not exist in ext/sqlite3).
 */
$db = new SQLite3(':memory:');
$db->exec('CREATE TABLE t(id INTEGER, name TEXT); INSERT INTO t VALUES (1,"a"),(2,"b");');
$r = $db->query('SELECT id, name FROM t ORDER BY id');
echo 'fetchAll=', method_exists($r, 'fetchAll') ? 'yes' : 'no', PHP_EOL;
$rows = $r->fetchAll(SQLITE3_ASSOC);
echo 'n=', is_array($rows) ? count($rows) : 'fail', PHP_EOL;
echo 'r0=', isset($rows[0]['name']) ? $rows[0]['name'] : '?', PHP_EOL;
echo 'r1=', isset($rows[1]['id']) ? (string) $rows[1]['id'] : '?', PHP_EOL;

$st = $db->prepare('SELECT 1');
echo 'busy=', method_exists($st, 'busy') ? 'yes' : 'no', PHP_EOL;
echo 'explain=', method_exists($st, 'explain') ? 'yes' : 'no', PHP_EOL;
echo 'setExplain=', method_exists($st, 'setExplain') ? 'yes' : 'no', PHP_EOL;
echo 'busy_val=', $st->busy() ? '1' : '0', PHP_EOL;
echo 'mode_const=', (string) SQLite3Stmt::EXPLAIN_MODE_PREPARED, PHP_EOL;
try {
    $mode = $st->explain();
    echo 'explain_mode=', (string) $mode, PHP_EOL;
} catch (Error $e) {
    echo 'explain_err=', $e->getMessage(), PHP_EOL;
}
try {
    $st->setExplain(99);
    echo "setExplain_bad=ok\n";
} catch (ValueError $e) {
    echo "setExplain_bad=ValueError\n";
} catch (Error $e) {
    echo 'setExplain_bad=', $e->getMessage(), PHP_EOL;
}
