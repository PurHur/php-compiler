--TEST--
intl grapheme_strlen/substr/strpos(null) TypeError on 8.4 forward (#20694, grapheme_string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
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
        echo $name, ' TypeError', "\n";
    }
}
echo grapheme_strlen(''), "\n";
?>
--EXPECT--
grapheme_strlen TypeError
grapheme_substr TypeError
grapheme_strpos TypeError
0
