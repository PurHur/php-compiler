<?php
/**
 * #23506 — sin/cos/tan/asin/acos/atan/exp/log/log10 Reflection $num
 * php-src: ext/standard/math.stub.php
 */
foreach (['sin', 'cos', 'tan', 'asin', 'acos', 'atan', 'exp', 'log10', 'log'] as $fn) {
    $r = new ReflectionFunction($fn);
    $p = $r->getParameters()[0];
    echo $fn, ' param=', $p->getName();
    echo ' type=', $p->hasType() ? (string) $p->getType() : '(none)';
    echo ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
}
echo 'sin=', (int) round(sin(num: M_PI / 2)), "\n";
echo 'cos=', (int) round(cos(num: 0)), "\n";
echo 'tan=', (int) round(tan(num: 0)), "\n";
echo 'asin=', (int) asin(num: 0), "\n";
echo 'acos=', (int) acos(num: 1), "\n";
echo 'atan=', (int) atan(num: 0), "\n";
echo 'exp=', (int) exp(num: 0), "\n";
echo 'log10=', (int) log10(num: 1), "\n";
echo 'log=', (int) log(num: 1), "\n";
echo 'log2=', (int) log(num: 8, base: 2), "\n";
try {
    sin(number: 0);
    echo "legacy number ok\n";
} catch (Throwable $e) {
    echo 'legacy number ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
