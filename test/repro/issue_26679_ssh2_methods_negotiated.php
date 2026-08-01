<?php
declare(strict_types=1);

/**
 * Repro #26679 — ssh2_methods_negotiated registration + optional live smoke.
 */
putenv('PHP_COMPILER_ENABLE_SSH2=1');
$_ENV['PHP_COMPILER_ENABLE_SSH2'] = '1';

$code = <<<'PHP'
<?php
echo 'ssh2_methods_negotiated=', function_exists('ssh2_methods_negotiated') ? 1 : 0, "\n";

$host = getenv('PHP_COMPILER_SSH2_TEST_HOST') ?: '';
$port = (int) (getenv('PHP_COMPILER_SSH2_TEST_PORT') ?: '22');
$user = getenv('PHP_COMPILER_SSH2_TEST_USER') ?: '';
$pass = getenv('PHP_COMPILER_SSH2_TEST_PASS') ?: '';
if ('' === $host || '' === $user) {
    echo "live_skip=1\n";
    exit(0);
}

$session = @ssh2_connect($host, $port);
if (!is_object($session)) {
    echo "live_connect_failed=1\n";
    exit(0);
}
$m = @ssh2_methods_negotiated($session);
echo 'is_array=', is_array($m) ? 1 : 0, "\n";
if (is_array($m)) {
    echo 'has_kex=', array_key_exists('kex', $m) ? 1 : 0, "\n";
    echo 'has_hostkey=', array_key_exists('hostkey', $m) ? 1 : 0, "\n";
    echo 'has_c2s=', array_key_exists('client_to_server', $m) ? 1 : 0, "\n";
    echo 'has_s2c=', array_key_exists('server_to_client', $m) ? 1 : 0, "\n";
}
ssh2_disconnect($session);
echo "ok\n";
PHP;

$tmp = sys_get_temp_dir() . '/phpc_ssh2_methods_' . getmypid() . '.php';
file_put_contents($tmp, $code);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/vm.php') . ' ' . escapeshellarg($tmp), $exit);
@unlink($tmp);
exit($exit);
