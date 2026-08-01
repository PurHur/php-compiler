<?php
declare(strict_types=1);

/**
 * Repro #26610 — ssh2_sftp_mkdir / rmdir / unlink registration + optional live smoke.
 */
putenv('PHP_COMPILER_ENABLE_SSH2=1');
$_ENV['PHP_COMPILER_ENABLE_SSH2'] = '1';

$code = <<<'PHP'
<?php
foreach (['ssh2_sftp_mkdir', 'ssh2_sftp_rmdir', 'ssh2_sftp_unlink'] as $fn) {
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
$base = '/home/' . $user . '/phpc_sftp_' . getmypid();
$nested = $base . '/a/b';
$file = $base . '/a/b/f.txt';
$mk = @ssh2_sftp_mkdir($sftp, $nested, 0755, true);
echo 'mkdir=', $mk ? 1 : 0, "\n";
// Create a file via scp_send into nested dir, then unlink
$local = sys_get_temp_dir() . '/phpc_sftp_ul_' . getmypid() . '.txt';
file_put_contents($local, "x\n");
@ssh2_scp_send($session, $local, $file, 0644);
@unlink($local);
$ul = @ssh2_sftp_unlink($sftp, $file);
echo 'unlink=', $ul ? 1 : 0, "\n";
$rm1 = @ssh2_sftp_rmdir($sftp, $base . '/a/b');
$rm2 = @ssh2_sftp_rmdir($sftp, $base . '/a');
$rm3 = @ssh2_sftp_rmdir($sftp, $base);
echo 'rmdir=', ($rm1 && $rm2 && $rm3) ? 1 : 0, "\n";
ssh2_disconnect($session);
echo "ok\n";
PHP;

$tmp = sys_get_temp_dir() . '/phpc_ssh2_sftp_mkdir_' . getmypid() . '.php';
file_put_contents($tmp, $code);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/vm.php') . ' ' . escapeshellarg($tmp), $exit);
@unlink($tmp);
exit($exit);
