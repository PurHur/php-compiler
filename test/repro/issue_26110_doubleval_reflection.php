<?php
/**
 * #26110 — doubleval() Reflection matches Zend type.stub.php (alias of floatval).
 * php-src: ext/standard/type.stub.php — function doubleval(mixed $value): float
 */
$r = new ReflectionFunction('doubleval');
echo 'params=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo 'param=[', $p, "]\n";
}
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
try {
    echo 'named=', doubleval(value: '3.5'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo 'pos=', doubleval('3.5'), "\n";
try {
    doubleval(var: '3.5');
    echo "legacy var ok\n";
} catch (Throwable $e) {
    echo 'legacy=', get_class($e), ':', $e->getMessage(), "\n";
}
