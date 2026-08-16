<?php
/**
 * #23334 — intval/floatval/strval/boolval Reflection names + Zend-style named args
 * php-src: ext/standard/basic_functions.stub.php / type.c
 */
foreach (['intval', 'floatval', 'strval', 'boolval'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, '=';
    foreach ($rf->getParameters() as $p) {
        echo $p->getName(), ',';
    }
    echo "\n";
}
try {
    echo intval(value: 'ff', base: 16), "\n";
} catch (Throwable $e) {
    echo 'intval ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(floatval(value: '1.5'));
    echo "\n";
} catch (Throwable $e) {
    echo 'floatval ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(strval(value: 42));
    echo "\n";
} catch (Throwable $e) {
    echo 'strval ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(boolval(value: 1));
    echo "\n";
} catch (Throwable $e) {
    echo 'boolval ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    intval(var: 'ff', base: 16);
    echo "legacy intval ok\n";
} catch (Throwable $e) {
    echo 'legacy intval ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
