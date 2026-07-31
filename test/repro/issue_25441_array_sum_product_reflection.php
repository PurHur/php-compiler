<?php
declare(strict_types=1);
// Repro #25441 — array_sum/array_product Reflection return int|float (array.stub.php)
foreach (['array_sum', 'array_product'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}
echo 'sum=', array_sum([1, 2, 3]), ' product=', array_product([2, 3, 4]), "\n";
