<?php

declare(strict_types=1);

// #25558 — ReflectionFunction::getStaticVariables() must include closure use-vars (php-src).

$a = 1;
$b = 'x';
$f = function () use ($a, $b) {
    return $a.$b;
};
$sv = (new ReflectionFunction($f))->getStaticVariables();
ksort($sv);
if ('{"a":1,"b":"x"}' !== json_encode($sv)) {
    fwrite(STDERR, 'use_only='.json_encode($sv)."\n");
    exit(1);
}

$n = 10;
$g = function () use ($n) {
    static $c = 0;
    $c++;

    return $n + $c;
};
$g();
$sv2 = (new ReflectionFunction($g))->getStaticVariables();
ksort($sv2);
if ('{"c":1,"n":10}' !== json_encode($sv2)) {
    fwrite(STDERR, 'use_plus_static='.json_encode($sv2)."\n");
    exit(1);
}

$ref = 'x';
$h = function () use (&$ref) {
    static $k = 0;
    $k++;
    $ref .= '!';

    return $k;
};
$h();
$sv3 = (new ReflectionFunction($h))->getStaticVariables();
ksort($sv3);
if ('{"k":1,"ref":"x!"}' !== json_encode($sv3)) {
    fwrite(STDERR, 'use_byref='.json_encode($sv3)."\n");
    exit(1);
}

echo "ok\n";
