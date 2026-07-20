--TEST--
stdlib mail.add_x_header=0 omits X-PHP-Originating-Script (#21433)
--INI--
sendmail_path={PWD}/mail_fixtures/mock_sendmail.sh
mail.add_x_header=0
--FILE--
<?php
$mock = ini_get('sendmail_path');
$out = dirname($mock) . '/mock_sendmail.last';
@unlink($out);
var_export(ini_get('mail.add_x_header'));
echo "\n";
mail('user@example.com', 'Hello', "Body\n", 'From: noreply@example.com');
$raw = is_file($out) ? file_get_contents($out) : '';
echo (str_contains($raw, 'X-PHP-Originating-Script:') ? 'has_x' : 'no_x'), "\n";
@unlink($out);
--EXPECT--
'0'
no_x
