<?php

/**
 * #24493 — ArrayObject::exchangeArray Reflection/named param is array (php-src spl_array.stub.php).
 */
$r = new ReflectionMethod('ArrayObject', 'exchangeArray');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'params=', implode(',', $names), "\n";

$ao = new ArrayObject(['a' => 1]);
try {
    $prev = $ao->exchangeArray(array: ['b' => 2]);
    echo 'named_array=', json_encode($prev), ' now=', json_encode($ao->getArrayCopy()), "\n";
} catch (Throwable $e) {
    echo 'named_array=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $prev = $ao->exchangeArray(ar: ['c' => 3]);
    echo 'named_ar=', json_encode($prev), ' now=', json_encode($ao->getArrayCopy()), "\n";
} catch (Throwable $e) {
    echo 'named_ar=', get_class($e), "\n";
}
