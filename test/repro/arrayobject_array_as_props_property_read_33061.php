<?php
declare(strict_types=1);
// Issue #33061 — ArrayObject ARRAY_AS_PROPS property read under AOT.
$a = new ArrayObject(['x' => 1], ArrayObject::ARRAY_AS_PROPS);
echo $a->x, PHP_EOL;
