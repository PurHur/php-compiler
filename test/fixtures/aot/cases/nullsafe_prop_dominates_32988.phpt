--TEST--
AOT: nullsafe ?-> property fetch matches Zend (dominates / #32988)
--FILE--
<?php
class A
{
    public $b = 1;
}
$a = null;
var_dump($a?->b);
$o = new A();
echo $o?->b, "\n";
echo ($a?->b ?? 'x'), "\n";
--EXPECT--
NULL
1
x
--EXPECT_EXIT--
0
