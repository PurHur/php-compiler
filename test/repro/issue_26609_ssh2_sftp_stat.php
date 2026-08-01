<?php
declare(strict_types=1);

/**
 * Repro #26609 — ssh2_sftp_stat / ssh2_sftp_lstat registration + optional live smoke.
 */
putenv('PHP_COMPILER_ENABLE_SSH2=1');
$_ENV['PHP_COMPILER_ENABLE_SSH2'] = '1';

$code = <<<'PHP'
<?php
foreach (['ssh2_sftp_stat', 'ssh2_sftp_lstat'] as $fn) {
    echo $fn, '=', function_exists($fn) ? 1 : 0, "\n";
}

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
$st = @ssh2_sftp_stat($sftp, '/');
echo 'stat_is_array=', is_array($st) ? 1 : 0, "\n";
echo 'stat_has_mode=', (int) (is_array($st) && array_key_exists('mode', $st)), "\n";
$missing = @ssh2_sftp_stat($sftp, '/phpc_ssh2_missing_' . getmypid());
echo 'missing_false=', ($missing === false) ? 1 : 0, "\n";
$lst = @ssh2_sftp_lstat($sftp, '/');
echo 'lstat_is_array=', is_array($lst) ? 1 : 0, "\n";
ssh2_disconnect($session);
echo "ok\n";
PHP;

$tmp = sys_get_temp_dir() . '/phpc_ssh2_sftp_stat_' . getmypid() . '.php';
file_put_contents($tmp, $code);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/vm.php') . ' ' . escapeshellarg($tmp), $exit);
@unlink($tmp);
exit($exit);
