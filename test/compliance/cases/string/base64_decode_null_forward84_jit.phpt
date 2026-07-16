--TEST--
stdlib base64_decode()/hex2bin()/quoted_printable_* null — TypeError on 8.4 forward profile JIT (#19283, ext/standard/base64.c, string.c, quot_print.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach ([
    'base64_decode' => static fn () => base64_decode(null),
    'hex2bin' => static fn () => hex2bin(null),
    'quoted_printable_encode' => static fn () => quoted_printable_encode(null),
    'quoted_printable_decode' => static fn () => quoted_printable_decode(null),
] as $label => $factory) {
    try {
        $factory();
        echo "{$label}: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
echo var_export(base64_decode(''), true), "\n";
?>
--EXPECT--
base64_decode(): Argument #1 ($string) must be of type string, null given
hex2bin(): Argument #1 ($string) must be of type string, null given
quoted_printable_encode(): Argument #1 ($string) must be of type string, null given
quoted_printable_decode(): Argument #1 ($string) must be of type string, null given
''
