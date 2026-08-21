--TEST--
AOT: ArrayObject ARRAY_AS_PROPS isset via __spl_ht (#33074, ext/spl/spl_array.c)
--FILE--
<?php
declare(strict_types=1);
$a = new ArrayObject(['x' => 1], ArrayObject::ARRAY_AS_PROPS);
echo isset($a->x) ? 'T' : 'F', PHP_EOL;
$a->y = 2;
echo isset($a->y) ? 'T' : 'F', PHP_EOL;
--EXPECT--
T
T
