--TEST--
AOT password_get_info() on password_hash() output (#3649)
--FILE--
<?php
$hash = password_hash('secret', PASSWORD_DEFAULT);
$info = password_get_info($hash);
echo $info['algo']."\n";
echo $info['algoName']."\n";
echo $info['options']['cost'] >= 4 ? "cost_ok\n" : "cost_low\n";
--EXPECTF--
2y
bcrypt
cost_ok
