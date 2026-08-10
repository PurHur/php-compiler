<?php
echo 'class=', class_exists('Pdo\\Sqlite') ? 'Y' : 'N', PHP_EOL;
echo 'meth=', method_exists('Pdo\\Sqlite', 'setAuthorizer') ? 'Y' : 'N', PHP_EOL;
if (!class_exists('Pdo\\Sqlite') || !method_exists('Pdo\\Sqlite', 'setAuthorizer')) {
    exit(0);
}
$db = new Pdo\Sqlite('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
$db->setAuthorizer(static function (int $action) {
    if ($action === Pdo\Sqlite::CREATE_TABLE) {
        return Pdo\Sqlite::DENY;
    }
    return Pdo\Sqlite::OK;
});
$denied = $db->exec('CREATE TABLE t(id INTEGER)');
echo 'denied=', (false === $denied) ? 'Y' : 'N', PHP_EOL;
$db->setAuthorizer(null);
$ok = $db->exec('CREATE TABLE t(id INTEGER)');
echo 'allowed=', (false !== $ok) ? 'Y' : 'N', PHP_EOL;
