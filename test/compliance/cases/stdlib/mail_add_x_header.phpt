--TEST--
stdlib mail.add_x_header X-PHP-Originating-Script (#21433, ext/standard/mail.c)
--INI--
sendmail_path={PWD}/mail_fixtures/mock_sendmail.sh
mail.add_x_header=1
--FILE--
<?php
$mock = ini_get('sendmail_path');
$out = dirname($mock) . '/mock_sendmail.last';
@unlink($out);
var_export(ini_get('mail.add_x_header'));
echo "\n";
$ok = mail('user@example.com', 'Hello', "Body\n", ['From' => 'noreply@example.com']);
var_export($ok);
echo "\n";
$raw = is_file($out) ? file_get_contents($out) : '';
echo (str_contains($raw, 'X-PHP-Originating-Script:') ? 'has_x' : 'no_x'), "\n";
echo (str_contains($raw, 'From: noreply@example.com') ? 'has_from' : 'no_from'), "\n";
@unlink($out);
--EXPECT--
'1'
true
has_x
has_from
