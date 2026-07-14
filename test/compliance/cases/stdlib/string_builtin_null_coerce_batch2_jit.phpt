--TEST--
stdlib Z_PARAM_STR builtins — null TypeError on 8.4 forward profile JIT (#18837, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach ([
    'nl2br' => static fn () => nl2br(null),
    'str_shuffle' => static fn () => str_shuffle(null),
    'str_rot13' => static fn () => str_rot13(null),
    'crc32' => static fn () => crc32(null),
    'soundex' => static fn () => soundex(null),
    'metaphone' => static fn () => metaphone(null),
    'convert_uuencode' => static fn () => convert_uuencode(null),
    'bin2hex' => static fn () => bin2hex(null),
    'hebrev' => static fn () => hebrev(null),
    'quoted_printable_encode' => static fn () => quoted_printable_encode(null),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
--EXPECT--
nl2br: nl2br(): Argument #1 ($string) must be of type string, null given
str_shuffle: str_shuffle(): Argument #1 ($string) must be of type string, null given
str_rot13: str_rot13(): Argument #1 ($string) must be of type string, null given
crc32: crc32(): Argument #1 ($string) must be of type string, null given
soundex: soundex(): Argument #1 ($string) must be of type string, null given
metaphone: metaphone(): Argument #1 ($string) must be of type string, null given
convert_uuencode: convert_uuencode(): Argument #1 ($string) must be of type string, null given
bin2hex: bin2hex(): Argument #1 ($string) must be of type string, null given
hebrev: hebrev(): Argument #1 ($string) must be of type string, null given
quoted_printable_encode: quoted_printable_encode(): Argument #1 ($string) must be of type string, null given
