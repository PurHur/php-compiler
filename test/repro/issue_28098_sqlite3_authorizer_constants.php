<?php
declare(strict_types=1);

/**
 * Repro for #28098 — SQLite3 authorizer / class constants after #25929 case-sensitive keys.
 * Run: PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/issue_28098_sqlite3_authorizer_constants.php
 */
echo 'setAuthorizer=', method_exists(SQLite3::class, 'setAuthorizer') ? 'yes' : 'no', "\n";
foreach (['OK', 'DENY', 'IGNORE', 'CREATE_TABLE', 'OPEN_READONLY'] as $c) {
    $refl = array_key_exists($c, (new ReflectionClass(SQLite3::class))->getConstants());
    echo $c, ' reflection=', $refl ? 'yes' : 'no';
    echo ' defined=', defined('SQLite3::'.$c) ? 'yes' : 'no';
    echo ' direct=', (string) constant('SQLite3::'.$c);
    echo "\n";
}
echo 'OK_eq=', (int) (SQLite3::OK === 0), "\n";
echo 'DENY_eq=', (int) (SQLite3::DENY === 1), "\n";
echo 'IGNORE_eq=', (int) (SQLite3::IGNORE === 2), "\n";
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
