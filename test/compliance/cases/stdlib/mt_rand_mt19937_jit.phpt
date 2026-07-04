--TEST--
stdlib mt_rand()/lcg_value() — JIT/AOT MT19937 + LCG (#3295)
--FILE--
<?php
mt_srand(12345);
echo mt_rand(), "\n";
echo mt_rand(1, 100), "\n";
$lcg = lcg_value();
echo ($lcg >= 0.0 && $lcg <= 1.0) ? "lcg_range=ok\n" : "lcg_range=bad\n";
--EXPECT--
1996335345
82
lcg_range=ok
