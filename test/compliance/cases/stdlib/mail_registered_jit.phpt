--TEST--
stdlib mail() registered — JIT returns false when transport unavailable (#12482)
--FILE--
<?php
var_export(function_exists('mail'));
echo "\n";
var_export(@mail('user@example.com', 'subject', 'body'));
echo "\n";
--EXPECT--
true
false
