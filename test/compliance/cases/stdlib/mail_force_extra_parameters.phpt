--TEST--
stdlib mail.force_extra_parameters overrides additional_params (#21434, ext/standard/mail.c)
--INI--
sendmail_path={PWD}/mail_fixtures/mock_sendmail_argv.sh
mail.force_extra_parameters=-f forced@example.com
--FILE--
<?php
$mock = ini_get('sendmail_path');
$forced = ini_get('mail.force_extra_parameters');
if (!is_string($mock) || '' === $mock || !is_executable($mock)) {
    echo "BAD_SENDMAIL_PATH\n";
    exit(1);
}
if ('-f forced@example.com' !== $forced) {
    echo 'BAD_FORCE_INI ', var_export($forced, true), "\n";
    exit(1);
}
$argvFile = dirname($mock) . '/mock_sendmail_argv.last';
@unlink($argvFile);

$ok = mail(
    'user@example.com',
    'Hello',
    "Body\n",
    '',
    '-f caller@example.com'
);
var_export($ok);
echo "\n";

if (!is_file($argvFile)) {
    echo "MISSING_ARGV\n";
    exit(1);
}
$recorded = trim((string) file_get_contents($argvFile));
$expected = escapeshellcmd('-f forced@example.com');
echo ($recorded === $expected ? 'force_used' : 'force_ignored'), "\n";
echo (str_contains($recorded, 'caller@example.com') ? 'caller_leaked' : 'caller_absent'), "\n";
@unlink($argvFile);
@unlink(dirname($mock) . '/mock_sendmail.last');
--EXPECT--
true
force_used
caller_absent
