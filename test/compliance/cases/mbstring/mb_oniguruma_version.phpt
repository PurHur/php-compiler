--TEST--
mbstring MB_ONIGURUMA_VERSION identity constant (#24083, ext/mbstring/mbstring.c)
--FILE--
<?php
echo defined('MB_ONIGURUMA_VERSION') ? gettype(constant('MB_ONIGURUMA_VERSION')) : 'undef', "\n";
echo is_string(MB_ONIGURUMA_VERSION) && MB_ONIGURUMA_VERSION !== '' ? 'version_ok' : 'version_bad', "\n";
echo preg_match('/^\d+\.\d+(\.\d+)?/', MB_ONIGURUMA_VERSION) ? 'shape_ok' : 'shape_bad', "\n";
echo defined('MB_CASE_UPPER') && MB_CASE_UPPER === 0 ? 'case_ok' : 'case_bad', "\n";
--EXPECT--
string
version_ok
shape_ok
case_ok
