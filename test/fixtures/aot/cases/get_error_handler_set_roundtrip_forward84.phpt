--TEST--
AOT: set_error_handler() then get_error_handler() round-trip (#17671, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

function eh($errno, $errstr, $file, $line)
{
    return true;
}

set_error_handler('eh');
echo get_error_handler() ?? 'null', "\n";
--EXPECT--
eh
