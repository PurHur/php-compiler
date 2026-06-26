--TEST--
stdlib grapheme_* / intl_get_error_code — not advertised without ext/intl (#11768, #11825)
--FILE--
<?php
declare(strict_types=1);

$phantom = function_exists('grapheme_strlen')
    || function_exists('grapheme_str_split')
    || function_exists('intl_get_error_code');
echo $phantom ? "fail\n" : "ok\n";
--EXPECT--
ok
