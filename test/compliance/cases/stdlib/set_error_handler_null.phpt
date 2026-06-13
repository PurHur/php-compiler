--TEST--
Stdlib: set_error_handler(null) clears custom handler (VM, #4463, Zend/zend_builtin_functions.c)
--FILE--
<?php
declare(strict_types=1);

function stack_null_handler(int $errno, string $errstr): bool {
    echo "custom\n";
    return true;
}

set_error_handler('stack_null_handler');
$previous = set_error_handler(null);
var_export(is_string($previous) && 'stack_null_handler' === $previous);
echo "\n";
trigger_error('after-null', E_USER_NOTICE);
echo "done\n";
--EXPECT--
PHP Notice:  after-null
true
done
