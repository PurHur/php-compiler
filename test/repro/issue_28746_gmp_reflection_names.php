<?php
/**
 * Repro #28746 — gmp_* Reflection param names/types + gmp_div alias (php-src-strict).
 * php-src: ext/gmp/gmp.stub.php
 */
foreach (['gmp_add', 'gmp_strval', 'gmp_pow', 'gmp_div_q', 'gmp_sub'] as $f) {
    if (!function_exists($f)) {
        echo $f, " missing\n";
        continue;
    }
    $rf = new ReflectionFunction($f);
    $ps = [];
    foreach ($rf->getParameters() as $p) {
        $ps[] = $p->getName() . ':' . ($p->hasType() ? (string) $p->getType() : '-');
    }
    echo $f, ' | ', implode(', ', $ps), "\n";
}
try {
    echo 'named ', (string) gmp_add(num1: 2, num2: 3), "\n";
} catch (Throwable $e) {
    echo 'named EX ', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo 'legacy ', (string) gmp_add(a: 2, b: 3), "\n";
} catch (Throwable $e) {
    echo 'legacy EX ', get_class($e), ':', $e->getMessage(), "\n";
}
echo 'gmp_div=', var_export(function_exists('gmp_div'), true),
    ' gmp_div_q=', var_export(function_exists('gmp_div_q'), true), "\n";
if (function_exists('gmp_div')) {
    echo 'div ', (string) gmp_div(num1: 10, num2: 2), "\n";
}
