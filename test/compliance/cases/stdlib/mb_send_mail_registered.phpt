--TEST--
stdlib mb_send_mail() registered — returns false when transport unavailable (#6548, ext/mbstring/mbstring.c)
--FILE--
<?php
var_export(function_exists('mb_send_mail'));
echo "\n";
var_export(@mb_send_mail('user@example.com', 'subject', 'body'));
echo "\n";
--EXPECT--
true
false
