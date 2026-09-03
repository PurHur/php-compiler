<?php
// Part of #36204 — ArrayObject ARRAY_AS_PROPS via SplArraySupport Module bridge (AOT+VM).
$ao = new ArrayObject(['x' => 1, 'y' => 2], ArrayObject::ARRAY_AS_PROPS);
echo $ao->x, $ao['y'], "\n";
$ao->z = 3;
echo $ao->z, count((array) $ao), "\n";
