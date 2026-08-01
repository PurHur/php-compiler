<?php
declare(strict_types=1);

/**
 * Repro #26662 — ssh2_sftp_symlink / ssh2_sftp_readlink registration + optional live smoke.
 */
putenv('PHP_COMPILER_ENABLE_SSH2=1');
$_ENV['PHP_COMPILER_ENABLE_SSH2'] = '1';

$code = <<<'PHP'
<?php
foreach (['ssh2_sftp_symlink', 'ssh2_sftp_readlink'] as $fn) {
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
$base = '/home/' . $user . '/phpc_sftp_ln_' . getmypid();
$target = $base . '_tgt.txt';
$link = $base . '_link';
$local = sys_get_temp_dir() . '/phpc_sftp_ln_' . getmypid() . '.txt';
file_put_contents($local, "ln\n");
@ssh2_scp_send($session, $local, $target, 0644);
@unlink($local);
$created = @ssh2_sftp_symlink($sftp, $target, $link);
echo 'symlink=', $created ? 1 : 0, "\n";
$read = @ssh2_sftp_readlink($sftp, $link);
echo 'readlink_ok=', (is_string($read) && $read === $target) ? 1 : 0, "\n";
$missing = @ssh2_sftp_readlink($sftp, '/phpc_ssh2_missing_ln_' . getmypid());
echo 'missing_false=', ($missing === false) ? 1 : 0, "\n";
@ssh2_sftp_unlink($sftp, $link);
@ssh2_sftp_unlink($sftp, $target);
ssh2_disconnect($session);
echo "ok\n";
PHP;

$tmp = sys_get_temp_dir() . '/phpc_ssh2_sftp_symlink_' . getmypid() . '.php';
file_put_contents($tmp, $code);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/vm.php') . ' ' . escapeshellarg($tmp), $exit);
@unlink($tmp);
exit($exit);
