--TEST--
AOT: BcMath\Number add/mul/compare stringification (#26803)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$n = new BcMath\Number("1.23");
echo (string) $n->add("2.77"), "\n";
echo (string) $n->mul(2), "\n";
echo (string) $n->compare("1.230"), "\n";
--EXPECT--
4.00
2.46
0
