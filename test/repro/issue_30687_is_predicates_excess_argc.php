<?php
/**
 * is_scalar / is_numeric / is_resource excess argc → ArgumentCountError (#30687).
 * php-src: Zend/zend_builtin_functions.c
 */
try {
    is_scalar(1, 1);
    echo "is_scalar_0:OK\n";
} catch (ArgumentCountError $e) {
    echo 'is_scalar_0:ArgumentCountError:', $e->getMessage(), "\n";
}
try {
    is_scalar(1, 1, 1);
    echo "is_scalar_1:OK\n";
} catch (ArgumentCountError $e) {
    echo 'is_scalar_1:ArgumentCountError:', $e->getMessage(), "\n";
}
echo 'is_scalar_2:OK:', is_scalar(1) ? 'true' : 'false', "\n";

try {
    is_numeric('1', 1);
    echo "is_numeric_0:OK\n";
} catch (ArgumentCountError $e) {
    echo 'is_numeric_0:ArgumentCountError:', $e->getMessage(), "\n";
}
try {
    is_numeric('1', 1, 1);
    echo "is_numeric_1:OK\n";
} catch (ArgumentCountError $e) {
    echo 'is_numeric_1:ArgumentCountError:', $e->getMessage(), "\n";
}
echo 'is_numeric_2:OK:', is_numeric('1') ? 'true' : 'false', "\n";

try {
    is_resource(1, 1);
    echo "is_resource_0:OK\n";
} catch (ArgumentCountError $e) {
    echo 'is_resource_0:ArgumentCountError:', $e->getMessage(), "\n";
}
try {
    is_resource(1, 1, 1);
    echo "is_resource_1:OK\n";
} catch (ArgumentCountError $e) {
    echo 'is_resource_1:ArgumentCountError:', $e->getMessage(), "\n";
}
echo 'is_resource_2:OK:', is_resource(1) ? 'true' : 'false', "\n";
