<?php
/**
 * #23306 — pow / sqrt / fmod Reflection names + Zend-style named args
 * php-src: ext/standard/basic_functions.stub.php
 */
foreach (['pow', 'sqrt', 'fmod'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, '=';
    foreach ($rf->getParameters() as $p) {
        echo $p->getName(), ',';
    }
    echo "\n";
}
try {
    var_export(pow(num: 2, exponent: 3));
    echo "\n";
} catch (Throwable $e) {
    echo 'pow ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(sqrt(num: 9.0));
    echo "\n";
} catch (Throwable $e) {
    echo 'sqrt ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(fmod(num1: 5.5, num2: 2.0));
    echo "\n";
} catch (Throwable $e) {
    echo 'fmod ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    pow(base: 2, exponent: 3);
    echo "legacy pow ok\n";
} catch (Throwable $e) {
    echo 'legacy pow ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    sqrt(number: 9.0);
    echo "legacy sqrt ok\n";
} catch (Throwable $e) {
    echo 'legacy sqrt ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    fmod(x: 5.5, y: 2.0);
    echo "legacy fmod ok\n";
} catch (Throwable $e) {
    echo 'legacy fmod ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
