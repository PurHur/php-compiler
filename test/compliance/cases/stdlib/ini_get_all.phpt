--TEST--
stdlib ini_get_all() returns directive metadata (issue #3205)
--FILE--
<?php
$all = ini_get_all();
echo isset($all['display_errors']) ? "all_ok\n" : "all_fail\n";
echo isset($all['display_errors']['global_value']) && isset($all['display_errors']['local_value']) ? "detail_ok\n" : "detail_fail\n";
$flat = ini_get_all(null, false);
echo is_string($flat['display_errors']) ? "flat_ok\n" : "flat_fail\n";
echo ini_get_all('nonexistent') === false ? "ext_false\n" : "ext_bad\n";
--EXPECT--
PHP Warning:  ini_get_all(): Extension "nonexistent" cannot be found in - on line 7
all_ok
detail_ok
flat_ok
ext_false
