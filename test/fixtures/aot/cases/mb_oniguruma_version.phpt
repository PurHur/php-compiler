--TEST--
AOT: MB_ONIGURUMA_VERSION identity constant (#24083)
--FILE--
<?php
echo defined('MB_ONIGURUMA_VERSION') ? 'yes' : 'no', "\n";
echo is_string(MB_ONIGURUMA_VERSION) && preg_match('/^\d+\.\d+(\.\d+)?/', MB_ONIGURUMA_VERSION) ? 'ok' : 'bad', "\n";
echo '[', MB_ONIGURUMA_VERSION, ']', "\n";
echo MB_CASE_UPPER, "\n";
--EXPECTF--
yes
ok
[%s]
0
