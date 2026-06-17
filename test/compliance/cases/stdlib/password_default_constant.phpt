--TEST--
stdlib PASSWORD_DEFAULT constant is string algo id 2y (ext/standard/password.c, #9173)
--FILE--
<?php
var_export(PASSWORD_DEFAULT);
echo "\n";
var_export(is_string(PASSWORD_DEFAULT));
echo "\n";
var_export(PASSWORD_DEFAULT === '2y');
echo "\n";
$hash = password_hash('secret', PASSWORD_DEFAULT);
echo password_verify('secret', $hash) ? "verify_ok\n" : "verify_fail\n";
--EXPECT--
'2y'
true
true
verify_ok
