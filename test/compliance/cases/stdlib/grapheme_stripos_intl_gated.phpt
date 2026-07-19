--TEST--
stdlib grapheme_stripos()/grapheme_stristr()/grapheme_strrpos() — not advertised without ext/intl (#11815, ext/intl/grapheme)
--FILE--
<?php
declare(strict_types=1);

$phantom = function_exists('grapheme_stripos')
    || function_exists('grapheme_stristr')
    || function_exists('grapheme_strrpos')
    || function_exists('grapheme_strripos');
echo $phantom ? "fail\n" : "ok\n";
--EXPECT--
ok
