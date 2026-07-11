--TEST--
stdlib rand() — MT19937 parity with Zend (ext/random/random.c, #11908)
--FILE--
<?php
echo function_exists('rand') ? "yes\n" : "no\n";
mt_srand(12345);
echo rand(), "\n";
echo rand(1, 100), "\n";
echo getrandmax(), "\n";
--EXPECT--
yes
1996335345
82
2147483647
