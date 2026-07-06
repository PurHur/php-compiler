--TEST--
stdlib mb_convert_variables() in-place encoding conversion (#4572, ext/mbstring/mbstring.c)
--FILE--
<?php
$a = 'hello';
$b = ['x' => 'world'];
$r = mb_convert_variables('UTF-8', 'ISO-8859-1', $a, $b);
echo $r, "\n";
echo $a, "\n";
echo $b['x'], "\n";
$o = new stdClass();
$o->label = 'ok';
$r2 = mb_convert_variables('UTF-8', 'UTF-8', $o);
echo $r2, "\n";
echo $o->label, "\n";
--EXPECT--
ISO-8859-1
hello
world
UTF-8
ok
