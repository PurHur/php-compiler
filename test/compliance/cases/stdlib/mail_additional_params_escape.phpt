--TEST--
stdlib mail() additional_params escapeshellcmd (#21434, ext/standard/mail.c)
--INI--
sendmail_path={PWD}/mail_fixtures/mock_sendmail_argv.sh
--FILE--
<?php
$mock = ini_get('sendmail_path');
if (!is_string($mock) || '' === $mock || !is_executable($mock)) {
    echo "BAD_SENDMAIL_PATH\n";
    exit(1);
}
$dir = dirname($mock);
$argvFile = $dir . '/mock_sendmail_argv.last';
$trap = $dir . '/inject_trap';
@unlink($argvFile);
@unlink($trap);

$extra = '; touch ' . $trap;
$ok = mail('user@example.com', 'Hello', "Body\n", '', $extra);
var_export($ok);
echo "\n";
echo is_file($trap) ? 'injected' : 'safe', "\n";

if (!is_file($argvFile)) {
    echo "MISSING_ARGV\n";
    exit(1);
}
$recorded = trim((string) file_get_contents($argvFile));
echo ($recorded === $extra ? 'argv_preserved' : 'argv_changed'), "\n";
@unlink($argvFile);
@unlink($trap);
@unlink($dir . '/mock_sendmail.last');
--EXPECT--
true
safe
argv_preserved
