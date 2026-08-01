<?php
declare(strict_types=1);

/**
 * Repro #26577 — ssh2_auth_pubkey_file.
 *
 * Optional live: PHP_COMPILER_SSH2_TEST_* with authorized_keys = fixtures/ssh2/id_rsa.pub
 */
putenv('PHP_COMPILER_ENABLE_SSH2=1');
$_ENV['PHP_COMPILER_ENABLE_SSH2'] = '1';

$fixtureRoot = realpath(__DIR__ . '/../fixtures/ssh2');
$fixtureExport = $fixtureRoot !== false ? $fixtureRoot : (__DIR__ . '/../fixtures/ssh2');

$code = <<<INNER
<?php
echo 'has_fn=', function_exists('ssh2_auth_pubkey_file') ? 1 : 0, "\n";

\$host = getenv('PHP_COMPILER_SSH2_TEST_HOST') ?: '';
\$port = (int) (getenv('PHP_COMPILER_SSH2_TEST_PORT') ?: '22');
\$user = getenv('PHP_COMPILER_SSH2_TEST_USER') ?: '';
if ('' === \$host || '' === \$user) {
    echo "live_skip=1\n";
    exit(0);
}

\$root = getenv('PHP_COMPILER_SSH2_FIXTURE_ROOT') ?: '{$fixtureExport}';
\$pub = getenv('PHP_COMPILER_SSH2_TEST_PUBKEY') ?: (\$root . '/id_rsa.pub');
\$priv = getenv('PHP_COMPILER_SSH2_TEST_PRIVKEY') ?: (\$root . '/id_rsa');
\$wrongPriv = \$root . '/id_rsa_wrong';
\$wrongPub = \$root . '/id_rsa_wrong.pub';

\$session = @ssh2_connect(\$host, \$port);
echo 'live_connect=', is_object(\$session) ? 1 : 0, "\n";
if (!is_object(\$session)) {
    echo "live_connect_failed=1\n";
    exit(0);
}
\$bad = @ssh2_auth_pubkey_file(\$session, \$user, \$wrongPub, \$wrongPriv);
echo 'auth_bad=', false === \$bad ? 1 : 0, "\n";
ssh2_disconnect(\$session);
\$session = @ssh2_connect(\$host, \$port);
\$ok = @ssh2_auth_pubkey_file(\$session, \$user, \$pub, \$priv);
echo 'auth_ok=', true === \$ok ? 1 : 0, "\n";
if (true === \$ok) {
    \$stream = @ssh2_exec(\$session, 'echo pubkey-ok');
    \$out = (false !== \$stream && null !== \$stream) ? @stream_get_contents(\$stream) : false;
    echo 'exec_match=', (is_string(\$out) && trim(\$out) === 'pubkey-ok') ? 1 : 0, "\n";
}
ssh2_disconnect(\$session);
echo "ok\n";
INNER;

$tmp = sys_get_temp_dir() . '/phpc_ssh2_pubkey_' . getmypid() . '.php';
file_put_contents($tmp, $code);
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/vm.php') . ' ' . escapeshellarg($tmp);
passthru($cmd, $exit);
@unlink($tmp);
exit($exit);
