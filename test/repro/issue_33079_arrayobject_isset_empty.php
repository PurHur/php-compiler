<?php
// Issue #33079 — ArrayObject isset[] + empty(AS_PROPS) under AOT.
$a = new ArrayObject(['z' => 0, 'x' => 1], ArrayObject::ARRAY_AS_PROPS);
echo isset($a['z']) ? 'T' : 'F', PHP_EOL;
echo empty($a->x) ? 'T' : 'F', PHP_EOL;
echo isset($a->z) ? 'T' : 'F', PHP_EOL;
