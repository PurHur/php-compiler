<?php
declare(strict_types=1);

/**
 * Repro #26661 — ssh2_sftp_realpath registration + optional live smoke.
 */
putenv('PHP_COMPILER_ENABLE_SSH2=1');
$_ENV['PHP_COMPILER_ENABLE_SSH2'] = '1';

$code = <<<'PHP'
<?php
echo 'ssh2_sftp_realpath=', function_exists('ssh2_sftp_realpath') ? 1 : 0, "\n";

$host = getenv('PHP_COMPILER_SSH2_TEST_HOST') ?: '';
$port = (int) (getenv('PHP_COMPILER_SSH2_TEST_PORT') ?: '22');
$user = getenv('PHP_COMPILER_SSH2_TEST_USER') ?: '';
$pass = getenv('PHP_COMPILER_SSH2_TEST_PASS') ?: '';
if ('' === $host || '' === $user) {
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
$rp = @ssh2_sftp_realpath($sftp, '.');
echo 'realpath_is_string=', is_string($rp) ? 1 : 0, "\n";
echo 'realpath_abs=', (is_string($rp) && str_starts_with($rp, '/')) ? 1 : 0, "\n";
$missing = @ssh2_sftp_realpath($sftp, '/phpc_ssh2_missing_rp_' . getmypid());
echo 'missing_false=', ($missing === false) ? 1 : 0, "\n";
ssh2_disconnect($session);
echo "ok\n";
PHP;

$tmp = sys_get_temp_dir() . '/phpc_ssh2_sftp_realpath_' . getmypid() . '.php';
file_put_contents($tmp, $code);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/vm.php') . ' ' . escapeshellarg($tmp), $exit);
@unlink($tmp);
exit($exit);
