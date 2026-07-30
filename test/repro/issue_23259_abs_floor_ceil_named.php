<?php
// Repro #23259 — abs/floor/ceil Zend stub named parameter (num)
$checks = [];
foreach (['abs', 'floor', 'ceil'] as $fn) {
    $names = [];
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        $names[] = $p->getName();
    }
    $checks[] = ['num'] === $names;
    try {
        if ('abs' === $fn) {
            $checks[] = 3 === abs(num: -3) && 3 === abs(-3);
        } elseif ('floor' === $fn) {
            $checks[] = 1.0 === floor(num: 1.5) && 1.0 === floor(1.5);
        } else {
            $checks[] = 2.0 === ceil(num: 1.2) && 2.0 === ceil(1.2);
        }
    } catch (Throwable $e) {
        $checks[] = false;
    }
    try {
        if ('abs' === $fn) {
            abs(number: -3);
        } elseif ('floor' === $fn) {
            floor(number: 1.5);
        } else {
            ceil(number: 1.2);
        }
        $checks[] = false;
    } catch (Error $e) {
        $checks[] = str_contains($e->getMessage(), 'Unknown named parameter $number');
    }
}

echo (!in_array(false, $checks, true)) ? "ok\n" : "fail\n";
