--TEST--
stdlib strtr() two-arg TypeError under declare(strict_types=1) (#16772, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
try {
    strtr('abc', 1);
    echo "int uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
strtr(): Argument #2 ($from) must be of type array|string, int given
