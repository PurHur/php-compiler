--TEST--
stdlib utf8_encode()/utf8_decode() scalar coercion + TypeError (#4317, ext/standard/basic_functions.c)
--FILE--
<?php
echo utf8_encode(65), "\n";
echo utf8_decode(195), "\n";
try {
    $unused = utf8_encode([]);
    echo "encode uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
try {
    $unused = utf8_decode(new stdClass());
    echo "decode uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
--EXPECT--
65
195
TypeError: utf8_encode(): Argument #1 ($string) must be of type string, array given
TypeError: utf8_decode(): Argument #1 ($string) must be of type string, stdClass given
