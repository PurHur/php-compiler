--TEST--
stdlib password_hash() JIT — bcrypt cost options array (#4741)
--FILE--
<?php
$h = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 10]);
$info = password_get_info($h);
echo strlen($h) > 20 ? "ok\n" : "fail\n";
var_export($info['options']['cost']);
echo "\n";
$cost = 11;
$h2 = password_hash('secret', PASSWORD_BCRYPT, ['cost' => $cost]);
$info2 = password_get_info($h2);
var_export($info2['options']['cost']);
echo "\n";
echo password_hash('secret', PASSWORD_BCRYPT, ['cost' => 3]) === false ? "bad_cost\n" : "bad_cost_fail\n";
--EXPECT--
ok
10
11
bad_cost
