<?php

var_export(pow('2', '3'));
echo "\n";
var_export(sqrt('4'));
echo "\n";
var_export(log('10'));
echo "\n";
try {
    pow([], 1);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
