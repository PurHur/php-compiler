--TEST--
switch comma-separated case labels — PHP 8 (issue #3608; Zend zend_compile.c)
--FILE--
<?php
switch (2) {
    case 1, 2:
        echo "hit\n";
        break;
    default:
        echo "miss\n";
}
switch (1) {
    case 1, 2:
        echo "one\n";
        break;
    default:
        echo "miss\n";
}
--EXPECT--
hit
one
