--TEST--
stdlib grapheme_str_contains()/grapheme_strimwidth() — withheld on PHP_COMPILER_PROFILE=8.2 (#22564, ext/intl)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
declare(strict_types=1);

foreach ([
    'grapheme_str_contains',
    'grapheme_strimwidth',
    'grapheme_str_split',
] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
    echo $fn, '_callable=', (int) is_callable($fn), "\n";
}
--EXPECT--
grapheme_str_contains=0
grapheme_str_contains_callable=0
grapheme_strimwidth=0
grapheme_strimwidth_callable=0
grapheme_str_split=0
grapheme_str_split_callable=0
