--TEST--
Return-by-reference AOT aliases caller storage (issue #4054, Zend zend_compile.c)
--FILE--
<?php
function &counter(): int {
    static $n = 0;
    return $n;
}
$r = &counter();
$r = 5;
echo counter(), "\n";
function &inc(): int {
    static $m = 1;
    return $m;
}
$x = inc();
echo $x, "\n";
$y = &inc();
$y = 9;
echo inc(), "\n";
class Box {
    public int $v = 0;
    public function &val(): int {
        return $this->v;
    }
}
$box = new Box();
$slot = &$box->val();
$slot = 7;
echo $box->v, "\n";
--EXPECT--
5
1
9
7
