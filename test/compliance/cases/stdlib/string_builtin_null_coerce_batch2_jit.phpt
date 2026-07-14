--TEST--
stdlib Z_PARAM_STR builtins — null coerces on 8.4 forward profile JIT (#18822, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach ([
    'nl2br(null)' => nl2br(null),
    'str_shuffle(null)' => str_shuffle(null),
    'str_rot13(null)' => str_rot13(null),
    'crc32(null)' => crc32(null),
    'soundex(null)' => soundex(null),
    'metaphone(null)' => metaphone(null),
    'convert_uuencode(null)' => convert_uuencode(null),
    'bin2hex(null)' => bin2hex(null),
    'hebrev(null)' => hebrev(null),
    'quoted_printable_encode(null)' => quoted_printable_encode(null),
] as $label => $result) {
    echo $label, ' => ';
    var_export($result);
    echo "\n";
}
--EXPECT--
nl2br(null) => ''
str_shuffle(null) => ''
str_rot13(null) => ''
crc32(null) => 0
soundex(null) => '0000'
metaphone(null) => ''
convert_uuencode(null) => '`
'
bin2hex(null) => ''
hebrev(null) => ''
quoted_printable_encode(null) => ''
