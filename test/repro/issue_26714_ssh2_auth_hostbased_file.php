<?php
declare(strict_types=1);

/**
 * Repro #26714 — ssh2_auth_hostbased_file registration + optional live smoke.
 */
putenv('PHP_COMPILER_ENABLE_SSH2=1');
$_ENV['PHP_COMPILER_ENABLE_SSH2'] = '1';

$code = <<<'PHP'
<?php
echo 'ssh2_auth_hostbased_file=', function_exists('ssh2_auth_hostbased_file') ? 1 : 0, "\n";

$host = getenv('PHP_COMPILER_SSH2_TEST_HOST') ?: '';
$port = (int) (getenv('PHP_COMPILER_SSH2_TEST_PORT') ?: '22');
$user = getenv('PHP_COMPILER_SSH2_TEST_USER') ?: '';
$pubkey = getenv('PHP_COMPILER_SSH2_TEST_HOST_PUBKEY') ?: '';
$privkey = getenv('PHP_COMPILER_SSH2_TEST_HOST_PRIVKEY') ?: '';
$hostname = getenv('PHP_COMPILER_SSH2_TEST_HOSTNAME') ?: gethostname();
if ('' === $host || '' === $user || '' === $pubkey || '' === $privkey) {
    echo "live_skip=1\n";
    exit(0);
}

$session = @ssh2_connect($host, $port, ['hostkey' => 'ssh-rsa']);
if (!is_object($session)) {
    echo "live_connect_failed=1\n";
    exit(0);
}
$ok = @ssh2_auth_hostbased_file($session, $user, $hostname, $pubkey, $privkey);
echo 'hostbased_auth=', $ok ? 1 : 0, "\n";
ssh2_disconnect($session);
echo "ok\n";
PHP;

$tmp = sys_get_temp_dir() . '/phpc_ssh2_auth_hostbased_' . getmypid() . '.php';
file_put_contents($tmp, $code);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/vm.php') . ' ' . escapeshellarg($tmp), $exit);
@unlink($tmp);
exit($exit);
