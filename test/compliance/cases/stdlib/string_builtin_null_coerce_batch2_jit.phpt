--TEST--
stdlib Z_PARAM_STR builtins — null TypeError/coerce mix on 8.4 JIT (#19161/#19309/#21181)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
foreach ([
    'nl2br' => static fn () => nl2br(null),
    'str_shuffle' => static fn () => str_shuffle(null),
    'str_rot13' => static fn () => str_rot13(null),
    'crc32' => static fn () => crc32(null),
    'convert_uuencode' => static fn () => convert_uuencode(null),
    'hebrev' => static fn () => hebrev(null),
    'quoted_printable_encode' => static fn () => quoted_printable_encode(null),
] as $label => $factory) {
    try {
        $result = $factory();
        echo "$label: ";
        var_export($result);
        echo "\n";
    } catch (TypeError $e) {
        echo "$label: ", $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
nl2br: ''
str_shuffle: ''
str_rot13: ''
crc32: 0
convert_uuencode: '`
'
hebrev: hebrev(): Argument #1 ($string) must be of type string, null given
quoted_printable_encode: ''
