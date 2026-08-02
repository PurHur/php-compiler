<?php
declare(strict_types=1);

/**
 * Repro #26717 — ssh2_publickey_* registration + type guards + optional live smoke.
 */
putenv('PHP_COMPILER_ENABLE_SSH2=1');
$_ENV['PHP_COMPILER_ENABLE_SSH2'] = '1';

$code = <<<'PHP'
<?php
$fns = ['ssh2_publickey_init', 'ssh2_publickey_add', 'ssh2_publickey_remove', 'ssh2_publickey_list'];
foreach ($fns as $fn) {
    echo $fn, '=', function_exists($fn) ? 1 : 0, "\n";
}

try {
    ssh2_publickey_add(null, 'ssh-rsa', 'blob');
    echo "type_fail=1\n";
} catch (TypeError $e) {
    echo "type_ok=1\n";
}

$host = getenv('PHP_COMPILER_SSH2_TEST_HOST') ?: '';
$port = (int) (getenv('PHP_COMPILER_SSH2_TEST_PORT') ?: '22');
$user = getenv('PHP_COMPILER_SSH2_TEST_USER') ?: '';
$pass = getenv('PHP_COMPILER_SSH2_TEST_PASSWORD') ?: '';
if ('' === $host || '' === $user || '' === $pass) {
    echo "live_skip=1\n";
    exit(0);
}

$session = @ssh2_connect($host, $port);
if (!is_object($session)) {
    echo "live_connect_failed=1\n";
    exit(0);
}
if (!@ssh2_auth_password($session, $user, $pass)) {
    echo "live_auth_failed=1\n";
    ssh2_disconnect($session);
    exit(0);
}
$pkey = @ssh2_publickey_init($session);
echo 'publickey_init=', is_object($pkey) ? 1 : 0, "\n";
if (is_object($pkey)) {
    $list = @ssh2_publickey_list($pkey);
    echo 'publickey_list=', is_array($list) ? 1 : 0, "\n";
}
ssh2_disconnect($session);
echo "ok\n";
PHP;

$tmp = sys_get_temp_dir() . '/phpc_ssh2_publickey_' . getmypid() . '.php';
file_put_contents($tmp, $code);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/vm.php') . ' ' . escapeshellarg($tmp), $exit);
@unlink($tmp);
exit($exit);
