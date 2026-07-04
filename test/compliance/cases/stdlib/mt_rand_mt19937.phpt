--TEST--
stdlib mt_rand()/srand()/lcg_value() — MT19937 + combined LCG parity (#3295, ext/random/random.c)
--FILE--
<?php
echo function_exists('mt_rand') ? "mt_rand=yes\n" : "mt_rand=no\n";
echo function_exists('srand') ? "srand=yes\n" : "srand=no\n";
echo function_exists('lcg_value') ? "lcg=yes\n" : "lcg=no\n";
mt_srand(12345);
echo mt_rand(), "\n";
echo mt_rand(1, 100), "\n";
srand(12345);
echo rand(), "\n";
echo rand(1, 100), "\n";
echo getrandmax(), "\n";
$lcg = lcg_value();
echo ($lcg >= 0.0 && $lcg <= 1.0) ? "lcg_range=ok\n" : "lcg_range=bad\n";
--EXPECT--
mt_rand=yes
srand=yes
lcg=yes
1996335345
82
1996335345
82
2147483647
lcg_range=ok
