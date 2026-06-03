--TEST--
stdlib strrev() scalar coercion + TypeError (#4552, ext/standard/string.c)
--FILE--
<?php
echo strrev(123), "\n";
echo strrev(null), "\n";
try {
    $unused = strrev([]);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
--EXPECT--
321

TypeError: strrev(): Argument #1 ($string) must be of type string, array given
