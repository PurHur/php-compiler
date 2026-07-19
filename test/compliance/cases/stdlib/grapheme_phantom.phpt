--TEST--
stdlib grapheme_* / intl_get_error_code — not advertised without ext/intl (#11768, #11825)
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
    || function_exists('intl_is_failure');
echo $phantom ? "fail\n" : "ok\n";
--EXPECT--
ok
