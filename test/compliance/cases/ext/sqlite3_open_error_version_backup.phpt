--TEST--
ext/sqlite3 open/lastError*/version/createCollation/backup (#20565, ext/sqlite3/sqlite3.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['open', 'lastErrorCode', 'lastErrorMsg', 'version', 'createCollation', 'backup', 'createFunction'] as $m) {
    echo $m, ':', method_exists(SQLite3::class, $m) ? 'yes' : 'NO', "\n";
}

$v = SQLite3::version();
echo 'ver_str=', is_string($v['versionString']) && '' !== $v['versionString'] ? 'ok' : 'bad', "\n";
echo 'ver_num=', is_int($v['versionNumber']) && $v['versionNumber'] > 0 ? 'ok' : 'bad', "\n";

$db = new SQLite3(':memory:');
echo 'idle_err=', $db->lastErrorCode(), "\n";
echo 'idle_msg=', $db->lastErrorMsg(), "\n";
$db->exec('CREATE TABLE t(x INT); INSERT INTO t VALUES (1);');
@$db->exec('SELECT no_such_column FROM t');
echo 'bad_err=', $db->lastErrorCode() > 0 ? 'pos' : 'zero', "\n";
echo 'bad_msg=', (false !== stripos($db->lastErrorMsg(), 'no such') || false !== stripos($db->lastErrorMsg(), 'column')) ? 'ok' : $db->lastErrorMsg(), "\n";

$ok = $db->createCollation('rev', function (string $a, string $b): int {
    return strcmp($b, $a);
});
echo 'collation=', $ok ? '1' : '0', "\n";

try {
    $db->open(':memory:');
    echo "open2=ok\n";
} catch (Throwable $e) {
    echo 'open2=', get_class($e), "\n";
}

$db->close();
echo 'closed_err=', $db->lastErrorCode(), "\n";
echo 'closed_msg=', var_export($db->lastErrorMsg(), true), "\n";
$db->open(':memory:');
$db->exec('CREATE TABLE u(y INT); INSERT INTO u VALUES (42);');
echo 'reopen_row=', $db->querySingle('SELECT y FROM u'), "\n";

$src = new SQLite3(':memory:');
$src->exec('CREATE TABLE t(v TEXT); INSERT INTO t VALUES ("hi");');
$dst = new SQLite3(':memory:');
echo 'backup=', $src->backup($dst) ? '1' : '0', "\n";
echo 'backup_row=', $dst->querySingle('SELECT v FROM t'), "\n";
?>
--EXPECT--
open:yes
lastErrorCode:yes
lastErrorMsg:yes
version:yes
createCollation:yes
backup:yes
createFunction:yes
ver_str=ok
ver_num=ok
idle_err=0
idle_msg=not an error
bad_err=pos
bad_msg=ok
collation=1
open2=Exception
closed_err=0
closed_msg=''
reopen_row=42
backup=1
backup_row=hi
