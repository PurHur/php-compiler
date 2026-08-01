<?php
declare(strict_types=1);

/**
 * Repro #26611 — ssh2_sftp_rename / ssh2_sftp_chmod registration + optional live smoke.
 */
putenv('PHP_COMPILER_ENABLE_SSH2=1');
$_ENV['PHP_COMPILER_ENABLE_SSH2'] = '1';

$code = <<<'PHP'
<?php
foreach (['ssh2_sftp_rename', 'ssh2_sftp_chmod'] as $fn) {
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
$base = '/home/' . $user . '/phpc_sftp_rn_' . getmypid();
$a = $base . '_a.txt';
$b = $base . '_b.txt';
$local = sys_get_temp_dir() . '/phpc_sftp_rn_' . getmypid() . '.txt';
file_put_contents($local, "rn\n");
@ssh2_scp_send($session, $local, $a, 0644);
@unlink($local);
$ren = @ssh2_sftp_rename($sftp, $a, $b);
echo 'rename=', $ren ? 1 : 0, "\n";
$ch = @ssh2_sftp_chmod($sftp, $b, 0600);
echo 'chmod=', $ch ? 1 : 0, "\n";
$st = @ssh2_sftp_stat($sftp, $b);
$modeOk = is_array($st) && isset($st['mode']) && (($st['mode'] & 0777) === 0600);
echo 'mode_ok=', $modeOk ? 1 : 0, "\n";
@ssh2_sftp_unlink($sftp, $b);
ssh2_disconnect($session);
echo "ok\n";
PHP;

$tmp = sys_get_temp_dir() . '/phpc_ssh2_sftp_rename_' . getmypid() . '.php';
file_put_contents($tmp, $code);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/vm.php') . ' ' . escapeshellarg($tmp), $exit);
@unlink($tmp);
exit($exit);
