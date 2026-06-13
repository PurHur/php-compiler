--TEST--
Stdlib: set_error_handler() error level mask (VM, #4463, Zend/zend_builtin_functions.c)
--FILE--
<?php
declare(strict_types=1);

set_error_handler(function (int $errno, string $errstr): bool {
    echo "handled:$errno\n";
    return true;
}, E_USER_WARNING);
trigger_error('notice', E_USER_NOTICE);
trigger_error('warn', E_USER_WARNING);
--EXPECT--
PHP Notice:  notice
handled:512
