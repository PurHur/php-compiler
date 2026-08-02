<?php
declare(strict_types=1);

/**
 * Repro #26716 — ssh2_auth_pubkey registration + optional live smoke.
 */
putenv('PHP_COMPILER_ENABLE_SSH2=1');
$_ENV['PHP_COMPILER_ENABLE_SSH2'] = '1';

$code = <<<'PHP'
<?php
echo 'ssh2_auth_pubkey=', function_exists('ssh2_auth_pubkey') ? 1 : 0, "\n";

$host = getenv('PHP_COMPILER_SSH2_TEST_HOST') ?: '';
$port = (int) (getenv('PHP_COMPILER_SSH2_TEST_PORT') ?: '22');
$user = getenv('PHP_COMPILER_SSH2_TEST_USER') ?: '';
$pubkeyFile = getenv('PHP_COMPILER_SSH2_TEST_PUBKEY') ?: (__DIR__ . '/../fixtures/ssh2/id_rsa.pub');
$privkeyFile = getenv('PHP_COMPILER_SSH2_TEST_PRIVKEY') ?: (__DIR__ . '/../fixtures/ssh2/id_rsa');
if ('' === $host || '' === $user) {
    echo "live_skip=1\n";
    exit(0);
}
if (!is_readable($pubkeyFile) || !is_readable($privkeyFile)) {
    echo "live_keys_missing=1\n";
    exit(0);
}
$pubkey = file_get_contents($pubkeyFile);
$privkey = file_get_contents($privkeyFile);
if (false === $pubkey || false === $privkey) {
    echo "live_keys_missing=1\n";
    exit(0);
}

$session = @ssh2_connect($host, $port);
if (!is_object($session)) {
    echo "live_connect_failed=1\n";
    exit(0);
}
$ok = @ssh2_auth_pubkey($session, $user, $pubkey, $privkey);
echo 'pubkey_auth=', $ok ? 1 : 0, "\n";
ssh2_disconnect($session);
echo "ok\n";
PHP;

$tmp = sys_get_temp_dir() . '/phpc_ssh2_auth_pubkey_' . getmypid() . '.php';
file_put_contents($tmp, $code);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/vm.php') . ' ' . escapeshellarg($tmp), $exit);
@unlink($tmp);
exit($exit);
