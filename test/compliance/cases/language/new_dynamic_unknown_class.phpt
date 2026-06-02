--TEST--
language: dynamic new unknown class throws catchable Error (zend_execute.c, #4242)
--FILE--
<?php
declare(strict_types=1);

$cls = 'NotARealClass';
try {
    new $cls();
    echo "no error\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    new NotARealClass();
    echo "literal no error\n";
} catch (Error $e) {
    echo 'literal: ', $e->getMessage(), "\n";
}

try {
    new NotARealClass();
} catch (Throwable $e) {
    echo 'throwable: ', get_class($e), "\n";
}
?>
--EXPECT--
Error: Class "NotARealClass" not found
literal: Class "NotARealClass" not found
throwable: Error
