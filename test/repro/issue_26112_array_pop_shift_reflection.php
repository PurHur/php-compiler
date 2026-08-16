<?php
declare(strict_types=1);

// Issue #26112 — array_pop/array_shift Reflection return mixed (array.stub.php)
foreach (['array_pop', 'array_shift'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
}

$a = [1, 2];
echo 'pop=', array_pop($a), PHP_EOL;
$b = [3, 4];
echo 'shift=', array_shift($b), PHP_EOL;
