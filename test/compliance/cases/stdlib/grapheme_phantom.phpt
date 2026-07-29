--TEST--
stdlib grapheme_* / GRAPHEME_EXTR_* / intl_get_error_* — not advertised without ext/intl (#11768, #11825, #24128)
--FILE--
<?php
declare(strict_types=1);

$phantom = function_exists('grapheme_strlen')
    || function_exists('grapheme_str_split')
    || function_exists('grapheme_str_contains')
    || function_exists('grapheme_strimwidth')
    || function_exists('grapheme_stripos')
    || function_exists('grapheme_stristr')
    || function_exists('grapheme_strrpos')
    || function_exists('grapheme_strripos')
    || function_exists('intl_get_error_code')
    || function_exists('intl_get_error_message')
    || function_exists('intl_is_failure')
    || defined('GRAPHEME_EXTR_COUNT')
    || defined('GRAPHEME_EXTR_MAXBYTES')
    || defined('GRAPHEME_EXTR_MAXCHARS');
echo $phantom ? "fail\n" : "ok\n";
echo extension_loaded('intl') ? "intl_yes\n" : "intl_no\n";
--EXPECT--
ok
intl_no
