--TEST--
stdlib lcfirst() scalar coercion + TypeError (#4729, ext/standard/string.c)
--FILE--
<?php
echo lcfirst('Hello'), "\n";
echo lcfirst('123abc'), "\n";
echo lcfirst(42), "\n";
try {
    $unused = lcfirst([]);
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
--EXPECT--
hello
123abc
42
TypeError: lcfirst(): Argument #1 ($string) must be of type string, array given
