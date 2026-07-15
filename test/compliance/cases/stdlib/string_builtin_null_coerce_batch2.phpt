--TEST--
stdlib Z_PARAM_STR builtins — null coerces on 8.4 forward profile (#19161 regression, was #18837)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
foreach ([
    'nl2br' => static fn () => nl2br(null),
    'str_shuffle' => static fn () => str_shuffle(null),
    'str_rot13' => static fn () => str_rot13(null),
    'crc32' => static fn () => crc32(null),
    // soundex/metaphone: TypeError on 8.4 — see phonetic_null_typeerror_84.phpt (#19243)
    'convert_uuencode' => static fn () => convert_uuencode(null),
    'bin2hex' => static fn () => bin2hex(null),
    'hebrev' => static fn () => hebrev(null),
    'quoted_printable_encode' => static fn () => quoted_printable_encode(null),
] as $label => $factory) {
    $result = $factory();
    echo "$label: ";
    var_export($result);
    echo "\n";
}
?>
--EXPECT--
nl2br: ''
str_shuffle: ''
str_rot13: ''
crc32: 0
convert_uuencode: '`
'
bin2hex: ''
hebrev: ''
quoted_printable_encode: ''
