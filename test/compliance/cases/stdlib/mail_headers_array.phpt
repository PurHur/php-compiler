--TEST--
stdlib mail() additional_headers array|string + ValueError (#21432, ext/standard/mail.c)
--INI--
sendmail_path={PWD}/mail_fixtures/mock_sendmail.sh
--FILE--
<?php
$mock = ini_get('sendmail_path');
$out = dirname($mock) . '/mock_sendmail.last';
@unlink($out);

try {
    mail('a@b.c', 'subj', 'body', ['To' => 'evil@x']);
    echo "TO_NO_THROW\n";
} catch (ValueError $e) {
    echo (str_contains($e->getMessage(), '"To"') ? 'to_value_error' : 'to_other'), "\n";
}

try {
    mail('a@b.c', 'subj', 'body', ['Subject' => 'x']);
    echo "SUBJ_NO_THROW\n";
} catch (ValueError $e) {
    echo (str_contains($e->getMessage(), '"Subject"') ? 'subj_value_error' : 'subj_other'), "\n";
}

try {
    mail('a@b.c', 'subj', 'body', ['From' => "a\nb"]);
    echo "LF_NO_THROW\n";
} catch (ValueError $e) {
    echo (str_contains($e->getMessage(), 'LF') ? 'lf_value_error' : 'lf_other'), "\n";
}

@unlink($out);
$ok = mail('user@example.com', 'Hello', "Body\n", ['From' => 'noreply@example.com']);
var_export($ok);
echo "\n";
$raw = is_file($out) ? file_get_contents($out) : '';
echo (str_contains($raw, 'From: noreply@example.com') ? 'has_from' : 'no_from'), "\n";
@unlink($out);
--EXPECT--
to_value_error
subj_value_error
lf_value_error
true
has_from
