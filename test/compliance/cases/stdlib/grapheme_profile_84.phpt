--TEST--
stdlib PHP 8.4 profile — grapheme helpers withheld without ext/intl (#17694, ext/intl/php_intl.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo (int) extension_loaded('intl'), "\n";
foreach ([
    'grapheme_str_contains',
    'grapheme_strimwidth',
    'grapheme_strlen',
    'grapheme_substr',
    'grapheme_strpos',
    'grapheme_extract',
    'grapheme_str_split',
] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
--EXPECT--
0
grapheme_str_contains=0
grapheme_strimwidth=0
grapheme_strlen=0
grapheme_substr=0
grapheme_strpos=0
grapheme_extract=0
grapheme_str_split=0
