<?php
declare(strict_types=1);

/**
 * Repro #26510 — ssh2_sftp / ssh2_scp_recv / ssh2_scp_send registration + optional live smoke.
 */
putenv('PHP_COMPILER_ENABLE_SSH2=1');
$_ENV['PHP_COMPILER_ENABLE_SSH2'] = '1';

$code = <<<'PHP'
<?php
foreach (['ssh2_sftp', 'ssh2_scp_recv', 'ssh2_scp_send'] as $fn) {
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
echo 'sftp=', is_object($sftp) ? 1 : 0, "\n";

$remote = '/home/' . $user . '/phpc_scp_' . getmypid() . '.txt';
$localSend = sys_get_temp_dir() . '/phpc_scp_send_' . getmypid() . '.txt';
$localRecv = sys_get_temp_dir() . '/phpc_scp_recv_' . getmypid() . '.txt';
file_put_contents($localSend, "hello-scp\n");
$sent = @ssh2_scp_send($session, $localSend, $remote, 0644);
echo 'scp_send=', $sent ? 1 : 0, "\n";
$recv = @ssh2_scp_recv($session, $remote, $localRecv);
echo 'scp_recv=', $recv ? 1 : 0, "\n";
echo 'match=', (int) (is_file($localRecv) && file_get_contents($localRecv) === "hello-scp\n"), "\n";
@unlink($localSend);
@unlink($localRecv);
ssh2_disconnect($session);
echo "ok\n";
PHP;

$tmp = sys_get_temp_dir() . '/phpc_ssh2_sftp_' . getmypid() . '.php';
file_put_contents($tmp, $code);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/vm.php') . ' ' . escapeshellarg($tmp), $exit);
@unlink($tmp);
exit($exit);
