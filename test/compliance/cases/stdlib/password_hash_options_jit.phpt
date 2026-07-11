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
try {
    password_hash('secret', PASSWORD_BCRYPT, ['cost' => 3]);
    echo "bad_cost_fail\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
ok
10
11
Invalid bcrypt cost parameter specified: 3
