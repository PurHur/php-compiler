<?php
/**
 * #27795 — deg2rad/rad2deg/expm1/log1p/asinh/acosh/atanh Reflection $num
 * php-src: ext/standard/basic_functions.stub.php
 */
foreach (['deg2rad', 'rad2deg', 'expm1', 'log1p', 'asinh', 'acosh', 'atanh'] as $fn) {
    $r = new ReflectionFunction($fn);
    $p = $r->getParameters()[0];
    echo $fn, ' param=', $p->getName();
    echo ' type=', $p->hasType() ? (string) $p->getType() : '(none)';
    echo ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
}
echo 'deg2rad=', (int) round(rad2deg(deg2rad(num: 180))), "\n";
echo 'asinh=', (int) asinh(num: 0), "\n";
try {
    deg2rad(number: 180);
    echo "legacy number ok\n";
} catch (Throwable $e) {
    echo 'legacy number ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
