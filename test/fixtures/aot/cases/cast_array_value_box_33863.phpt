--TEST--
AOT: (array) cast — value-box PHI + ArrayObject/null/scalar (#33863, Zend/zend_operators.c)
--FILE--
<?php
echo implode(',', (array)[1, 2]), "\n";
echo '[', implode(',', (array) null), "]\n";
$a = [3, 4];
echo implode(',', (array) $a), "\n";
echo implode(',', (array) 7), "\n";
class T
{
    public $x = 1;
    public $y = 2;
}
echo implode(',', (array) (new T)), "\n";
$ao = new ArrayObject([5, 6]);
echo implode(',', (array) $ao), "\n";
echo implode(',', (array) ($ao->getArrayCopy())), "\n";
?>
--EXPECT--
1,2
[]
3,4
7
1,2
5,6
5,6
