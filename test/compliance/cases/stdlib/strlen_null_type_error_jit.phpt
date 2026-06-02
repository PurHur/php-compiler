--TEST--
stdlib strlen() JIT — TypeError when argument is null (#4365)
--FILE--
<?php
try {
    var_export(strlen(null));
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: strlen(): Argument #1 ($string) must be of type string, null given
