--TEST--
stdlib array_walk() inline new object subject + closure callback (#17504)
--FILE--
<?php
declare(strict_types=1);

array_walk(new ArrayObject([1]), static function (&$v, $k): void {
    $v = 2;
});
echo "ok\n";
--EXPECT--
ok
