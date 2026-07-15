--TEST--
stdlib phonetic/distance null TypeError on 8.4 forward profile (#19243, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'levenshtein' => static fn () => levenshtein(null, 'a'),
    'metaphone' => static fn () => metaphone(null),
    'soundex' => static fn () => soundex(null),
    'similar_text' => static fn () => similar_text(null, 'a'),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
--EXPECT--
levenshtein: levenshtein(): Argument #1 ($string1) must be of type string, null given
metaphone: metaphone(): Argument #1 ($string) must be of type string, null given
soundex: soundex(): Argument #1 ($string) must be of type string, null given
similar_text: similar_text(): Argument #1 ($string1) must be of type string, null given
