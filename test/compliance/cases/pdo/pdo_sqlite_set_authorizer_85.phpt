--TEST--
ext Pdo\Sqlite::setAuthorizer on PROFILE=8.5 (#27676)
--ENV--
PHP_COMPILER_PROFILE=8.5
PHP_COMPILER_ENABLE_PDO_SQLITE=1
--FILE--
<?php
declare(strict_types=1);
echo 'meth=', (int) method_exists(Pdo\Sqlite::class, 'setAuthorizer'), "\n";
echo 'OK=', (int) defined('Pdo\\Sqlite::OK'), "\n";
echo 'DENY=', (int) defined('Pdo\\Sqlite::DENY'), "\n";
$db = new Pdo\Sqlite('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
$db->setAuthorizer(static function (int $action) {
    if ($action === Pdo\Sqlite::CREATE_TABLE) {
        return Pdo\Sqlite::DENY;
    }
    return Pdo\Sqlite::OK;
});
$denied = @$db->exec('CREATE TABLE t(id INTEGER)');
echo 'create_denied=', (int) (false === $denied), "\n";
$db->setAuthorizer(null);
$ok = $db->exec('CREATE TABLE t(id INTEGER)');
echo 'create_allowed=', (int) (false !== $ok), "\n";
?>
--EXPECT--
meth=1
OK=1
DENY=1
create_denied=1
create_allowed=1
