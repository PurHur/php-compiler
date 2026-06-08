--TEST--
stdlib hexdec()/bindec()/octdec() scalar-to-string coercion (#4217, ext/standard/math.c)
--FILE--
<?php
echo hexdec(1.5), "\n";
echo octdec(7.0), "\n";
echo bindec(1010), "\n";
try {
    hexdec([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
21
7
10
hexdec(): Argument #1 ($hex_string) must be of type string, array given
