--TEST--
language: inline static closure compiles as direct call argument (#12694, zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

$seen = 0;
set_error_handler(static function (int $errno, string $message) use (&$seen): bool {
    ++$seen;

    return true;
});
trigger_error('probe', E_USER_WARNING);
echo $seen, "\n";
--EXPECT--
1
