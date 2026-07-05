--TEST--
Language: duplicate named parameter — runtime Error catchable (zend_execute.c, #16652)
--FILE--
<?php
function sum(int $a, int $b = 0): int {
    return $a + $b;
}
try {
    sum(a: 1, a: 2, b: 3);
    echo "fail\n";
} catch (Error $e) {
    echo 'ok:' . $e->getMessage() . "\n";
}
?>
--EXPECT--
ok:Named parameter $a overwrites previous argument
