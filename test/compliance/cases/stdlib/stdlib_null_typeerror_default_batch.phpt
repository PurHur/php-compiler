--TEST--
stdlib Z_PARAM_STR null TypeError on default profile — define/phonetic/encoding (#18931, #18932, #18934, ext/standard)
--FILE--
<?php
foreach ([
    'define' => static fn () => define(null, 1),
    'levenshtein' => static fn () => levenshtein(null, 'a'),
    'metaphone' => static fn () => metaphone(null),
    'soundex' => static fn () => soundex(null),
    'similar_text' => static fn () => similar_text(null, 'a'),
    'hebrev' => static fn () => hebrev(null),
    'convert_uuencode' => static fn () => convert_uuencode(null),
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
define: define(): Argument #1 ($constant_name) must be of type string, null given
levenshtein: levenshtein(): Argument #1 ($string1) must be of type string, null given
metaphone: metaphone(): Argument #1 ($string) must be of type string, null given
soundex: soundex(): Argument #1 ($string) must be of type string, null given
similar_text: similar_text(): Argument #1 ($string1) must be of type string, null given
hebrev: hebrev(): Argument #1 ($string) must be of type string, null given
convert_uuencode: convert_uuencode(): Argument #1 ($string) must be of type string, null given
quoted_printable_encode: quoted_printable_encode(): Argument #1 ($string) must be of type string, null given
