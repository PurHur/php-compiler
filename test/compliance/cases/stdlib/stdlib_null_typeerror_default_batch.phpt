--TEST--
stdlib Z_PARAM_STR null coerce on default profile — phonetic/encoding (#18957, ext/standard/string.c)
--FILE--
<?php
foreach ([
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
levenshtein: uncaught
metaphone: uncaught
soundex: uncaught
similar_text: uncaught
hebrev: uncaught
convert_uuencode: uncaught
quoted_printable_encode: uncaught
