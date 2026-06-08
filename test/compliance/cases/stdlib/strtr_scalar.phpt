--TEST--
stdlib strtr() scalar coercion + TypeError (#4336, ext/standard/string.c)
--FILE--
<?php
echo strtr(123, '1', '9'), "\n";
echo strtr(true, ['1' => '9']), "\n";
try {
    strtr([], 'a', 'b');
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
923
9
TypeError: strtr(): Argument #1 ($string) must be of type string, array given
