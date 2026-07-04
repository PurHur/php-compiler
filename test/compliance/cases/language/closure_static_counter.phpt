--TEST--
Closure static counter in multi-arg call — var_dump($g(), $g()) (issue #16029, Zend/zend_closures.c)
--FILE--
<?php
$g = function (): int {
    static $n = 0;
    return ++$n;
};
ob_start();
var_dump($g(), $g());
echo ob_get_clean();
--EXPECT--
int(1)
int(2)
