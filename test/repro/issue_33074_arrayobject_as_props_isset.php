<?php
declare(strict_types=1);
// Issue #33074 — ArrayObject ARRAY_AS_PROPS isset under AOT (leftover #33068).
$a = new ArrayObject(['x' => 1], ArrayObject::ARRAY_AS_PROPS);
echo isset($a->x) ? 'T' : 'F', PHP_EOL;
$a->y = 2;
echo isset($a->y) ? 'T' : 'F', PHP_EOL;
