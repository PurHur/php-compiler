--TEST--
isset() on undefined object property returns false (issue #3603)
--FILE--
<?php
class A {
    public $x = 1;
}
$a = new A();
echo isset($a->y) ? 'y' : 'n', "\n";
echo isset($a->x) ? 'y' : 'n', "\n";
unset($a->x);
echo isset($a->x) ? 'y' : 'n', "\n";
--EXPECT--
n
y
n
