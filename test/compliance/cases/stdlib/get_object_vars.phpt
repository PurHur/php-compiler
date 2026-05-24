--TEST--
Stdlib: get_object_vars() on object (VM, #1370)
--FILE--
<?php
class Box {
    public $x = 1;
    public $y = 'hi';
    private $hidden = 9;
}
$o = new Box();
$v = get_object_vars($o);
echo count($v);
echo $v['x'];
echo $v['y'];
echo isset($v['hidden']) ? '1' : '0';
unset($o->x);
$w = get_object_vars($o);
echo count($w);
echo isset($w['x']) ? '1' : '0';
echo "\n";
--EXPECT--
31hi120
