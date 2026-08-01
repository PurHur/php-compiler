<?php
declare(strict_types=1);

/**
 * Repro #26575 — ssh2_fingerprint hostkey hash + SSH2_FINGERPRINT_* constants.
 *
 * Outer process sets PHP_COMPILER_ENABLE_SSH2=1 then runs under bin/vm.php.
 * Optional live sshd: PHP_COMPILER_SSH2_TEST_HOST / _PORT
 */
putenv('PHP_COMPILER_ENABLE_SSH2=1');
$_ENV['PHP_COMPILER_ENABLE_SSH2'] = '1';

$code = <<<'PHP'
<?php
echo 'has_fn=', function_exists('ssh2_fingerprint') ? 1 : 0, "\n";
echo 'c_md5=', defined('SSH2_FINGERPRINT_MD5') ? (string) SSH2_FINGERPRINT_MD5 : 'x', "\n";
echo 'c_sha1=', defined('SSH2_FINGERPRINT_SHA1') ? (string) SSH2_FINGERPRINT_SHA1 : 'x', "\n";
echo 'c_hex=', defined('SSH2_FINGERPRINT_HEX') ? (string) SSH2_FINGERPRINT_HEX : 'x', "\n";
echo 'c_raw=', defined('SSH2_FINGERPRINT_RAW') ? (string) SSH2_FINGERPRINT_RAW : 'x', "\n";

$host = getenv('PHP_COMPILER_SSH2_TEST_HOST') ?: '';
$port = (int) (getenv('PHP_COMPILER_SSH2_TEST_PORT') ?: '22');
if ('' === $host) {
    echo "live_skip=1\n";
    exit(0);
}

$session = @ssh2_connect($host, $port);
echo 'live_connect=', is_object($session) ? 1 : 0, "\n";
if (!is_object($session)) {
    echo "live_connect_failed=1\n";
    exit(0);
}
$md5 = @ssh2_fingerprint($session);
echo 'md5_ok=', (is_string($md5) && strlen($md5) === 32 && ctype_xdigit($md5)) ? 1 : 0, "\n";
$sha1 = @ssh2_fingerprint($session, SSH2_FINGERPRINT_SHA1 | SSH2_FINGERPRINT_HEX);
echo 'sha1_ok=', (is_string($sha1) && strlen($sha1) === 40 && ctype_xdigit($sha1)) ? 1 : 0, "\n";
$raw = @ssh2_fingerprint($session, SSH2_FINGERPRINT_MD5 | SSH2_FINGERPRINT_RAW);
echo 'raw_ok=', (is_string($raw) && strlen($raw) === 16) ? 1 : 0, "\n";
if (is_string($md5) && is_string($raw)) {
    echo 'raw_hex_match=', (strtoupper(bin2hex($raw)) === strtoupper($md5)) ? 1 : 0, "\n";
} else {
    echo "raw_hex_match=0\n";
}
ssh2_disconnect($session);
echo "ok\n";
PHP;

$tmp = sys_get_temp_dir() . '/phpc_ssh2_fp_' . getmypid() . '.php';
file_put_contents($tmp, $code);
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/vm.php') . ' ' . escapeshellarg($tmp);
passthru($cmd, $exit);
@unlink($tmp);
exit($exit);
