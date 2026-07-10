--TEST--
stdlib grapheme_* on PHP 8.4 profile — advertised without ext/intl (#17608, ext/intl/grapheme)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$advertised = function_exists('grapheme_strlen')
    && function_exists('grapheme_substr')
    && function_exists('grapheme_strpos')
    && function_exists('grapheme_extract')
    && function_exists('grapheme_str_split')
    && function_exists('grapheme_str_contains')
    && function_exists('grapheme_strimwidth');
$gated = function_exists('grapheme_stripos')
    || function_exists('grapheme_stristr')
    || function_exists('grapheme_strrpos');
echo $advertised && !$gated ? "ok\n" : "fail\n";
--EXPECT--
ok
