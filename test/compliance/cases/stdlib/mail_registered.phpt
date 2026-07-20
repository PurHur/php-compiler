--TEST--
stdlib mail() registered — returns false when sendmail exits non-zero (#12482/#3285)
--FILE--
<?php
var_export(function_exists('mail'));
echo "\n";
// Default sendmail_path points at missing binary in CI image → pclose != 0 → false
var_export(@mail('user@example.com', 'subject', 'body'));
echo "\n";
--EXPECT--
true
false
