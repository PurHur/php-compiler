<?php
declare(strict_types=1);

/**
 * Repro #26509 — ssh2 libssh2 handshake + auth_password.
 *
 * Outer process sets PHP_COMPILER_ENABLE_SSH2=1 then runs under bin/vm.php.
 * Optional live sshd: PHP_COMPILER_SSH2_TEST_HOST / _PORT / _USER / _PASS
 */
putenv('PHP_COMPILER_ENABLE_SSH2=1');
$_ENV['PHP_COMPILER_ENABLE_SSH2'] = '1';

$code = <<<'PHP'
<?php
echo 'has_fn=', function_exists('ssh2_connect') ? 1 : 0, "\n";

$conn = @ssh2_connect('127.0.0.1', 1);
echo 'closed_port=', false === $conn ? 1 : 0, "\n";

$host = getenv('PHP_COMPILER_SSH2_TEST_HOST') ?: '';
$port = (int) (getenv('PHP_COMPILER_SSH2_TEST_PORT') ?: '22');
$user = getenv('PHP_COMPILER_SSH2_TEST_USER') ?: '';
$pass = getenv('PHP_COMPILER_SSH2_TEST_PASS') ?: '';
if ('' === $host || '' === $user) {
    echo "live_skip=1\n";
    exit(0);
}

$session = @ssh2_connect($host, $port);
echo 'live_connect=', is_object($session) ? 1 : 0, "\n";
if (!is_object($session)) {
    // libssh2 missing or sshd unreachable — not a hard fail for CI without deps.
    echo "live_connect_failed=1\n";
    exit(0);
}
$bad = @ssh2_auth_password($session, $user, $pass . '-wrong');
echo 'auth_bad=', false === $bad ? 1 : 0, "\n";
$ok = @ssh2_auth_password($session, $user, $pass);
echo 'auth_ok=', true === $ok ? 1 : 0, "\n";
ssh2_disconnect($session);
echo "ok\n";
PHP;

$tmp = sys_get_temp_dir() . '/phpc_ssh2_handshake_' . getmypid() . '.php';
file_put_contents($tmp, $code);
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/vm.php') . ' ' . escapeshellarg($tmp);
passthru($cmd, $exit);
@unlink($tmp);
exit($exit);
