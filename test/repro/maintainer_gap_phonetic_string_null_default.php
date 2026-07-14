<?php
// #18931 — phonetic/distance string builtins null TypeError on default profile.
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
