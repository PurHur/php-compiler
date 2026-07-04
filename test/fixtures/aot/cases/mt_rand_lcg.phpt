--TEST--
AOT mt_rand() + lcg_value() seeded parity (#3295)
--FILE--
<?php
mt_srand(12345);
$a = mt_rand(1, 100);
$b = mt_rand(1, 100);
echo "$a $b\n";
echo (lcg_value() >= 0.0 && lcg_value() <= 1.0) ? "ok\n" : "bad\n";
--EXPECT--
91 82
ok
