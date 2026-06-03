--TEST--
stdlib ucfirst() scalar coercion + TypeError (#4729, ext/standard/string.c)
--FILE--
<?php
echo ucfirst('hello'), "\n";
echo ucfirst('123abc'), "\n";
echo ucfirst(42), "\n";
try {
    $unused = ucfirst([]);
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
--EXPECT--
Hello
123abc
42
TypeError: ucfirst(): Argument #1 ($string) must be of type string, array given
