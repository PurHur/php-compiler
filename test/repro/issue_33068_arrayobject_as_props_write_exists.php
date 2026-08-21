<?php
// Issue #33068 — ArrayObject ARRAY_AS_PROPS write + property_exists under AOT.
$a = new ArrayObject(['x' => 1], ArrayObject::ARRAY_AS_PROPS);
echo property_exists($a, 'x') ? 'T' : 'F', PHP_EOL;
$a->y = 2;
echo $a->y, PHP_EOL;
