--TEST--
ArrayObject ARRAY_AS_PROPS property write + property_exists AOT (#33068)
--FILE--
<?php
$a = new ArrayObject(['x' => 1], ArrayObject::ARRAY_AS_PROPS);
echo property_exists($a, 'x') ? 'T' : 'F', "\n";
$a->y = 2;
echo $a->y, "\n";
echo $a['y'], "\n";
--EXPECT--
T
2
2
