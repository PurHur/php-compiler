--TEST--
stdlib mail() sendmail_path popen transport (#3285, ext/standard/mail.c)
--INI--
sendmail_path={PWD}/mail_fixtures/mock_sendmail.sh
--FILE--
<?php
$mock = ini_get('sendmail_path');
if (!is_string($mock) || '' === $mock || !is_executable($mock)) {
    echo "BAD_SENDMAIL_PATH\n";
    exit(1);
}
$out = dirname($mock) . '/mock_sendmail.last';
@unlink($out);

$ok = mail(
    'user@example.com',
    'Hello',
    "Body line\n",
    "From: noreply@example.com"
);
var_export($ok);
echo "\n";
if (!is_file($out)) {
    echo "MISSING_CAPTURE\n";
    exit(1);
}
$raw = file_get_contents($out);
echo (str_contains($raw, "To: user@example.com") ? 'has_to' : 'no_to'), "\n";
echo (str_contains($raw, "Subject: Hello") ? 'has_subj' : 'no_subj'), "\n";
echo (str_contains($raw, "From: noreply@example.com") ? 'has_from' : 'no_from'), "\n";
echo (str_contains($raw, "Body line") ? 'has_body' : 'no_body'), "\n";
@unlink($out);
--EXPECT--
true
has_to
has_subj
has_from
has_body
