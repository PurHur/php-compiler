--TEST--
stdlib grapheme_* on PHP 8.4 profile — not advertised without ext/intl (#17694, ext/intl/php_intl.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$phantom = function_exists('grapheme_strlen')
    || function_exists('grapheme_substr')
    || function_exists('grapheme_strpos')
    || function_exists('grapheme_extract')
    || function_exists('grapheme_str_split')
    || function_exists('grapheme_str_contains')
    || function_exists('grapheme_strimwidth')
    || function_exists('grapheme_stripos')
    || function_exists('grapheme_stristr')
    || function_exists('grapheme_strrpos');
echo $phantom ? "fail\n" : "ok\n";
--EXPECT--
ok
