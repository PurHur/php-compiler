<?php
declare(strict_types=1);

/**
 * Repro #26740 — ssh2_sftp_statvfs registration + type guard + optional live smoke.
 */
putenv('PHP_COMPILER_ENABLE_SSH2=1');
$_ENV['PHP_COMPILER_ENABLE_SSH2'] = '1';

$code = <<<'PHP'
<?php
echo 'ssh2_sftp_statvfs=', function_exists('ssh2_sftp_statvfs') ? 1 : 0, "\n";

try {
    ssh2_sftp_statvfs(null, '/');
    echo "type_fail=1\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), 'SSH2\\Sftp') || str_contains($e->getMessage(), 'SSH2\Sftp'))
        ? "type_ok=1\n"
        : "type_msg=1\n";
}

$host = getenv('PHP_COMPILER_SSH2_TEST_HOST') ?: '';
$port = (int) (getenv('PHP_COMPILER_SSH2_TEST_PORT') ?: '22');
$user = getenv('PHP_COMPILER_SSH2_TEST_USER') ?: '';
$pass = getenv('PHP_COMPILER_SSH2_TEST_PASSWORD')
    ?: (getenv('PHP_COMPILER_SSH2_TEST_PASS') ?: '');
if ('' === $host || '' === $user || '' === $pass) {
    echo "live_skip=1\n";
    exit(0);
}

$session = @ssh2_connect($host, $port);
if (!is_object($session) || !@ssh2_auth_password($session, $user, $pass)) {
    echo "live_auth_failed=1\n";
    exit(0);
}
$sftp = @ssh2_sftp($session);
if (!is_object($sftp)) {
    echo "live_sftp_failed=1\n";
    exit(0);
}
$st = @ssh2_sftp_statvfs($sftp, '.');
echo 'statvfs_is_array=', is_array($st) ? 1 : 0, "\n";
if (is_array($st)) {
    $keys = ['bsize', 'frsize', 'blocks', 'bfree', 'bavail', 'files', 'ffree', 'favail', 'fsid', 'flag', 'namemax'];
    $have = 0;
    foreach ($keys as $k) {
        if (array_key_exists($k, $st)) {
            ++$have;
        }
    }
    echo 'statvfs_keys=', $have, "\n";
}
ssh2_disconnect($session);
echo "ok\n";
PHP;

$tmp = sys_get_temp_dir() . '/phpc_ssh2_sftp_statvfs_' . getmypid() . '.php';
file_put_contents($tmp, $code);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/vm.php') . ' ' . escapeshellarg($tmp), $exit);
@unlink($tmp);
exit($exit);
