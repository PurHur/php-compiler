--TEST--
stdlib is_a() — TypeError for non-object/non-allowed-string subject (#4782, ext/standard/class.c)
--FILE--
<?php
try {
    var_dump(is_a(1, 'stdClass'));
} catch (Throwable $e) {
    echo $e::class, "\n";
    echo $e->getMessage(), "\n";
}
try {
    var_dump(is_a(1, 'stdClass', true));
} catch (Throwable $e) {
    echo $e::class, "\n";
    echo $e->getMessage(), "\n";
}
class Widget {}
try {
    var_dump(is_a('Widget', 'Widget'));
} catch (Throwable $e) {
    echo $e::class, "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
is_a(): Argument #1 ($object_or_class) must be of type object|string, int given
TypeError
is_a(): Argument #1 ($object_or_class) must be of type object|string, int given
TypeError
is_a(): Argument #1 ($object_or_class) must be of type object|string, string given
