<?php
/**
 * get_resource_type() excess argc → ArgumentCountError (#30707).
 * php-src: Zend/zend_builtin_functions.c
 */
$f = fopen('php://memory', 'r');
try {
    get_resource_type($f, 'x');
    echo "excess_2:OK\n";
} catch (ArgumentCountError $e) {
    echo 'excess_2:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'excess_2:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    get_resource_type($f, 'x', 'y');
    echo "excess_3:OK\n";
} catch (ArgumentCountError $e) {
    echo 'excess_3:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'excess_3:', get_class($e), ':', $e->getMessage(), "\n";
}
echo 'ok:', get_resource_type($f), "\n";
fclose($f);
