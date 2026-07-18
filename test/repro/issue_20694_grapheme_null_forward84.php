<?php
foreach ([
    'grapheme_strlen' => static fn () => grapheme_strlen(null),
    'grapheme_substr' => static fn () => grapheme_substr(null, 0),
    'grapheme_strpos' => static fn () => grapheme_strpos(null, 'a'),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ' COERCED ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $name, " TypeError\n";
    }
}
echo grapheme_strlen(''), "\n";
echo grapheme_strlen('café'), "\n";
