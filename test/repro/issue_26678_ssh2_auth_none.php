<?php
declare(strict_types=1);

/**
 * Repro #26678 — ssh2_auth_none registration + optional live smoke.
 */
putenv('PHP_COMPILER_ENABLE_SSH2=1');
$_ENV['PHP_COMPILER_ENABLE_SSH2'] = '1';

$code = <<<'PHP'
<?php
echo 'ssh2_auth_none=', function_exists('ssh2_auth_none') ? 1 : 0, "\n";

$host = getenv('PHP_COMPILER_SSH2_TEST_HOST') ?: '';
$port = (int) (getenv('PHP_COMPILER_SSH2_TEST_PORT') ?: '22');
$user = getenv('PHP_COMPILER_SSH2_TEST_USER') ?: '';
if ('' === $host || '' === $user) {
    echo "live_skip=1\n";
    exit(0);
}

$session = @ssh2_connect($host, $port);
if (!is_object($session)) {
    echo "live_connect_failed=1\n";
    exit(0);
}
$r = @ssh2_auth_none($session, $user);
echo 'result_type=', gettype($r), "\n";
if (is_array($r)) {
    echo 'methods=', implode(',', $r), "\n";
} elseif (is_bool($r)) {
    echo 'bool=', $r ? 1 : 0, "\n";
}
ssh2_disconnect($session);
echo "ok\n";
PHP;

$tmp = sys_get_temp_dir() . '/phpc_ssh2_auth_none_' . getmypid() . '.php';
file_put_contents($tmp, $code);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/vm.php') . ' ' . escapeshellarg($tmp), $exit);
@unlink($tmp);
exit($exit);
