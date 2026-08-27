--TEST--
AOT mb_convert_variables() string/array/object by-ref (#35315 leftover #4572)
--FILE--
<?php
$latin1Cafe = "caf\xe9";
$a = $latin1Cafe;
$r = mb_convert_variables('UTF-8', 'ISO-8859-1', $a);
echo $r, "\n";
echo bin2hex($a), "\n";
$b = ['x' => 'world'];
$r2 = mb_convert_variables('UTF-8', 'ISO-8859-1', $b);
echo $r2, "\n";
echo $b['x'], "\n";
$o = new stdClass();
$o->label = 'ok';
$r3 = mb_convert_variables('UTF-8', 'UTF-8', $o);
echo $r3, "\n";
echo $o->label, "\n";
--EXPECT--
ISO-8859-1
636166c3a9
ISO-8859-1
world
UTF-8
ok
