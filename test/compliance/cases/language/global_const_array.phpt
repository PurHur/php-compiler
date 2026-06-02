--TEST--
Language: global const array literals (issue #4526, Zend/zend_constants.c)
--FILE--
<?php
const FOO = [1, 2, 3];
echo FOO[1], "\n";
const BAR = [[4, 5], 6];
echo BAR[0][1], BAR[1], "\n";
--EXPECT--
2
56
