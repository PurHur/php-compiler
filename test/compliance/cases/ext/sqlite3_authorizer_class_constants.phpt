--TEST--
SQLite3::OK/DENY/IGNORE/CREATE_* accessible + defined() (#28098)
--ENV--
PHP_COMPILER_PROFILE=8.5
--SKIPIF--
<?php
if (!class_exists('SQLite3')) die('skip no SQLite3');
?>
--FILE--
<?php
echo 'OK=', (int) SQLite3::OK, "\n";
echo 'DENY=', (int) SQLite3::DENY, "\n";
echo 'IGNORE=', (int) SQLite3::IGNORE, "\n";
echo 'CREATE_TABLE=', (int) SQLite3::CREATE_TABLE, "\n";
echo 'OPEN_READONLY=', (int) SQLite3::OPEN_READONLY, "\n";
echo 'defined_OK=', defined('SQLite3::OK') ? 'yes' : 'no', "\n";
echo 'defined_DENY=', defined('SQLite3::DENY') ? 'yes' : 'no', "\n";
echo 'defined_CREATE_TABLE=', defined('SQLite3::CREATE_TABLE') ? 'yes' : 'no', "\n";
echo 'constant_OK=', (int) constant('SQLite3::OK'), "\n";
$db = new SQLite3(':memory:');
$db->setAuthorizer(static function (int $action) {
    if ($action === SQLite3::CREATE_TABLE) {
        return SQLite3::DENY;
    }
    return SQLite3::OK;
});
$denied = @$db->exec('CREATE TABLE t(id INTEGER)');
echo 'create_denied=', false === $denied ? 'yes' : 'no', "\n";
$db->setAuthorizer(null);
$allowed = $db->exec('CREATE TABLE t(id INTEGER)');
echo 'create_allowed=', false !== $allowed ? 'yes' : 'no', "\n";
?>
--EXPECT--
OK=0
DENY=1
IGNORE=2
CREATE_TABLE=2
OPEN_READONLY=1
defined_OK=yes
defined_DENY=yes
defined_CREATE_TABLE=yes
constant_OK=0
create_denied=yes
create_allowed=yes
