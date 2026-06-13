--TEST--
Stdlib: set_error_handler() stack push/pop + return values (VM, #4463, Zend/zend_builtin_functions.c)
--FILE--
<?php
declare(strict_types=1);

$h1 = function (int $errno, string $errstr): bool {
    echo "h1:$errno\n";
    return true;
};
$h2 = function (int $errno, string $errstr): bool {
    echo "h2:$errno\n";
    return true;
};

var_export(set_error_handler($h1));
echo "\n";
var_export(set_error_handler($h2) !== null);
echo "\n";
restore_error_handler();
trigger_error('x', E_USER_NOTICE);
--EXPECT--
NULL
true
h1:1024
