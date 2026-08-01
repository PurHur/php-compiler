<?php
declare(strict_types=1);

/**
 * Repro #26576 — ssh2_exec channel + readable stdout.
 *
 * Optional live: PHP_COMPILER_SSH2_TEST_HOST / _PORT / _USER / _PASS
 */
putenv('PHP_COMPILER_ENABLE_SSH2=1');
$_ENV['PHP_COMPILER_ENABLE_SSH2'] = '1';

$code = <<<'PHP'
<?php
echo 'has_fn=', function_exists('ssh2_exec') ? 1 : 0, "\n";

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
    echo "live_connect_failed=1\n";
    exit(0);
}
$auth = @ssh2_auth_password($session, $user, $pass);
echo 'auth_ok=', true === $auth ? 1 : 0, "\n";
if (true !== $auth) {
    echo "auth_failed=1\n";
    exit(0);
}
$stream = @ssh2_exec($session, 'echo phpc-ssh2-exec');
$okStream = false !== $stream && null !== $stream && false !== $stream;
echo 'exec_ok=', ($okStream && false !== $stream) ? 1 : 0, "\n";
if (false === $stream || null === $stream) {
    echo "exec_failed=1\n";
    exit(0);
}
$out = @stream_get_contents($stream);
echo 'out=', json_encode($out), "\n";
echo 'match=', (is_string($out) && trim($out) === 'phpc-ssh2-exec') ? 1 : 0, "\n";
@fclose($stream);
ssh2_disconnect($session);
echo "ok\n";
PHP;

$tmp = sys_get_temp_dir() . '/phpc_ssh2_exec_' . getmypid() . '.php';
file_put_contents($tmp, $code);
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/vm.php') . ' ' . escapeshellarg($tmp);
passthru($cmd, $exit);
@unlink($tmp);
exit($exit);
