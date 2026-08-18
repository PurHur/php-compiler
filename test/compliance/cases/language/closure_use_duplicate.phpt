--TEST--
Language: duplicate closure use() name is compile-time fatal (#32153, Zend/zend_compile.c)
--FILE--
<?php
$a = 1;
$f = function () use ($a, $a) {
    return $a;
};
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Cannot use variable $a twice in %s on line %d
