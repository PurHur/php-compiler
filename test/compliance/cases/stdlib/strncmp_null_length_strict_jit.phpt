--TEST--
stdlib strncmp/strncasecmp(null $length) JIT TypeError under strict_types (#31265, ext/standard/string.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
foreach (['strncmp', 'strncasecmp'] as $fn) {
    try {
        $fn('a', 'b', null);
        echo "fail\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
strncmp(): Argument #3 ($length) must be of type int, null given
strncasecmp(): Argument #3 ($length) must be of type int, null given
