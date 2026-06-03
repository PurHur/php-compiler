--TEST--
Language: foreach by-reference over object properties (#3661, Zend zend_foreach.c)
--FILE--
<?php
class C {
    public int $a = 1;
}
$o = new C();
foreach ($o as &$v) {
    $v = 2;
}
echo $o->a, "\n";

$o2 = (object) ['a' => 1];
foreach ($o2 as &$v) {
    $v = 2;
}
echo $o2->a, "\n";

class Multi {
    public int $a = 1;
    public int $b = 2;
}
$m = new Multi();
foreach ($m as &$v) {
    $v = 9;
}
echo $m->a, ',', $m->b, "\n";
?>
--EXPECT--
2
2
9,9
