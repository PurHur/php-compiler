--TEST--
stdlib is_a() JIT — TypeError for invalid subject (#4782)
--FILE--
<?php
try {
    var_dump(is_a(1, 'stdClass'));
} catch (Throwable $e) {
    echo $e::class, "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
is_a(): Argument #1 ($object_or_class) must be of type object|string, int given
