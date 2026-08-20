--TEST--
AOT: ArrayObject ARRAY_AS_PROPS property read via __spl_ht (#33061, ext/spl/spl_array.c)
--FILE--
<?php
declare(strict_types=1);
$a = new ArrayObject(['x' => 1], ArrayObject::ARRAY_AS_PROPS);
echo $a->x, PHP_EOL;
--EXPECT--
1
