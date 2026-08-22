--TEST--
AOT: chained instance ??= compiles and stores like Zend (#33760)
--FILE--
<?php
class A33760Aot
{
    public $p;
}

class B33760Aot
{
    public $q;
}

$a = new A33760Aot();
$b = new B33760Aot();
$a->p ??= $b->q ??= 9;
var_dump($a->p, $b->q);
$a->p ??= $b->q ??= 1;
var_dump($a->p, $b->q);
?>
--EXPECT--
int(9)
int(9)
int(9)
int(9)
--EXPECT_EXIT--
0
