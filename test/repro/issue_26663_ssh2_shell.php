<?php
declare(strict_types=1);

/**
 * Repro #26663 — ssh2_shell registration + optional live smoke.
 */
putenv('PHP_COMPILER_ENABLE_SSH2=1');
$_ENV['PHP_COMPILER_ENABLE_SSH2'] = '1';

$code = <<<'PHP'
<?php
echo 'ssh2_shell=', function_exists('ssh2_shell') ? 1 : 0, "\n";
echo 'SSH2_TERM_UNIT_CHARS=', defined('SSH2_TERM_UNIT_CHARS') ? (int) SSH2_TERM_UNIT_CHARS : -1, "\n";
echo 'SSH2_TERM_UNIT_PIXELS=', defined('SSH2_TERM_UNIT_PIXELS') ? (int) SSH2_TERM_UNIT_PIXELS : -1, "\n";

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
$stream = @ssh2_shell($session, 'xterm');
echo 'shell_is_object=', is_object($stream) ? 1 : 0, "\n";
echo 'shell_class=', is_object($stream) ? get_class($stream) : '', "\n";
ssh2_disconnect($session);
echo "ok\n";
PHP;

$tmp = sys_get_temp_dir() . '/phpc_ssh2_shell_' . getmypid() . '.php';
file_put_contents($tmp, $code);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/vm.php') . ' ' . escapeshellarg($tmp), $exit);
@unlink($tmp);
exit($exit);
