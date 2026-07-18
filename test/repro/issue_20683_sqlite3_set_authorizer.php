<?php
/**
 * Repro #20683 — SQLite3::setAuthorizer + OK/DENY/IGNORE.
 */
$db = new SQLite3(':memory:');
echo 'method=', method_exists($db, 'setAuthorizer') ? '1' : '0', "\n";
foreach (['OK', 'DENY', 'IGNORE'] as $c) {
    echo $c, '=', defined('SQLite3::'.$c) ? '1' : '0', "\n";
}
echo 'OK_VAL=', (string) SQLite3::OK, "\n";
echo 'DENY_VAL=', (string) SQLite3::DENY, "\n";
echo 'IGNORE_VAL=', (string) SQLite3::IGNORE, "\n";
$db->setAuthorizer(static function (int $action) {
    if ($action === SQLite3::CREATE_TABLE) {
        return SQLite3::DENY;
    }
    return SQLite3::OK;
});
echo 'installed=1', "\n";
$ok = @$db->exec('CREATE TABLE t(id INTEGER)');
echo 'create_denied=', false === $ok ? '1' : '0', "\n";
$db->setAuthorizer(null);
$ok2 = $db->exec('CREATE TABLE t(id INTEGER)');
echo 'create_allowed=', false !== $ok2 ? '1' : '0', "\n";
