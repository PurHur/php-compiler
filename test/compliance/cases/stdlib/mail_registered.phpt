--TEST--
stdlib mail() registered — returns false when transport unavailable (#12482, ext/standard/mail.c)
--FILE--
<?php
var_export(function_exists('mail'));
echo "\n";
var_export(@mail('user@example.com', 'subject', 'body'));
echo "\n";
--EXPECT--
true
false
