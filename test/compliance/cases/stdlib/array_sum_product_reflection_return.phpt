--TEST--
array_sum / array_product Reflection return int|float (#25441, ext/standard/array.stub.php)
--FILE--
<?php
declare(strict_types=1);
foreach (['array_sum', 'array_product'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}
echo 'sum=', array_sum([1, 2, 3]), ' product=', array_product([2, 3, 4]), "\n";
--EXPECT--
array_sum ret=int|float
array_product ret=int|float
sum=6 product=24
