--TEST--
Modulo truncates float operands to int like Zend (zend_operators.c mod_function)
--FILE--
<?php
echo 5.7 % 2.2, "\n";
echo 5.9 % 2, "\n";
echo -5.7 % 2.2, "\n";
echo '7' % '3', "\n";
try {
    var_dump(1 % 0);
} catch (DivisionByZeroError $e) {
    echo "div0\n";
}
--EXPECT--
1
1
-1
1
div0
