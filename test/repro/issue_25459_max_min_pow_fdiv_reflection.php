<?php
/**
 * #25459 — max/min/pow/fdiv Reflection types/names + Zend named args
 * php-src: ext/standard/math.stub.php, basic_functions.stub.php
 *
 *   ./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/issue_25459_max_min_pow_fdiv_reflection.php'
 */
foreach (['max', 'min', 'pow', 'fdiv', 'fmod'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)';
    echo ' params=';
    foreach ($r->getParameters() as $p) {
        echo '$', $p->getName();
        if ($p->hasType()) {
            echo ':', (string) $p->getType();
        }
        if ($p->isVariadic()) {
            echo '...';
        }
        echo ',';
    }
    echo "\n";
}
try {
    echo 'pow_named=', var_export(pow(num: 2, exponent: 3), true), "\n";
} catch (Throwable $e) {
    echo 'pow_named ERR=', $e->getMessage(), "\n";
}
try {
    echo 'fmod_named=', var_export(fmod(num1: 5.5, num2: 2.0), true), "\n";
} catch (Throwable $e) {
    echo 'fmod_named ERR=', $e->getMessage(), "\n";
}
try {
    pow(base: 2, exponent: 3);
    echo "legacy_pow_ok\n";
} catch (Throwable $e) {
    echo 'legacy_pow ERR=', $e->getMessage(), "\n";
}
