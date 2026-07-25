--TEST--
ext/sqlite3 SQLite3::setAuthorizer + OK/DENY/IGNORE (#20683)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('SQLite3')) die('skip no SQLite3');
?>
--FILE--
<?php
declare(strict_types=1);
$db = new SQLite3(':memory:');
echo 'method=', (int) method_exists($db, 'setAuthorizer'), "\n";
echo 'OK=', (int) defined('SQLite3::OK'), "\n";
echo 'DENY=', (int) defined('SQLite3::DENY'), "\n";
echo 'IGNORE=', (int) defined('SQLite3::IGNORE'), "\n";
echo 'OK_VAL=', (int) SQLite3::OK, "\n";
echo 'DENY_VAL=', (int) SQLite3::DENY, "\n";
echo 'IGNORE_VAL=', (int) SQLite3::IGNORE, "\n";
$db->setAuthorizer(static function (int $action) {
    if ($action === SQLite3::CREATE_TABLE) {
        return SQLite3::DENY;
    }
    return SQLite3::OK;
});
echo 'installed=1', "\n";
$ok = @$db->exec('CREATE TABLE t(id INTEGER)');
echo 'create_denied=', (int) (false === $ok), "\n";
$db->setAuthorizer(null);
$ok2 = $db->exec('CREATE TABLE t(id INTEGER)');
echo 'create_allowed=', (int) (false !== $ok2), "\n";
?>
--EXPECT--
method=1
OK=1
DENY=1
IGNORE=1
OK_VAL=0
DENY_VAL=1
IGNORE_VAL=2
installed=1
create_denied=1
create_allowed=1
