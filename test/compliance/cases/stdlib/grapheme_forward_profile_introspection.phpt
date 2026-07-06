--TEST--
stdlib grapheme_* on PHP 8.4 profile — not advertised without ext/intl (#11803, ext/intl/grapheme)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$phantom = function_exists('grapheme_strlen')
    || function_exists('grapheme_substr')
    || function_exists('grapheme_strpos')
    || function_exists('grapheme_extract')
    || function_exists('grapheme_str_split');
echo $phantom ? "fail\n" : "ok\n";
--EXPECT--
ok
