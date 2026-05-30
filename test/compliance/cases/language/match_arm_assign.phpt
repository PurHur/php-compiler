--TEST--
match arm assignment binds variable when arm matches (issue #3787; Zend zend_compile.c)
--FILE--
<?php
match (1) {
    1 => $x = 2,
    default => 0,
};
echo $x, "\n";
match (0) {
    1 => $x = 99,
    default => 0,
};
echo $x, "\n";
--EXPECT--
2
2
