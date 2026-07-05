--TEST--
stdlib ini_get_all(null, false) mirrors host Zend registry (#16433, ext/standard/ini.c)
--FILE--
<?php
$flat = ini_get_all(null, false);
$count = count($flat);
echo $count >= 200 ? "count_ok\n" : "count_fail {$count}\n";
echo isset($flat['allow_url_fopen']) ? "allow_url_fopen_ok\n" : "allow_url_fopen_fail\n";
echo is_string($flat['allow_url_fopen']) ? "allow_url_fopen_string_ok\n" : "allow_url_fopen_string_fail\n";

$session = ini_get_all('session', false);
echo is_array($session) && count($session) >= 20 ? "session_ok\n" : "session_fail\n";
echo isset($session['session.use_cookies']) ? "session_use_cookies_ok\n" : "session_use_cookies_fail\n";
--EXPECT--
count_ok
allow_url_fopen_ok
allow_url_fopen_string_ok
session_ok
session_use_cookies_ok
