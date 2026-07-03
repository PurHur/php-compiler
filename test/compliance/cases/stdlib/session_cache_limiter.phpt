--TEST--
stdlib session_cache_limiter() get/set before session_start() (#11095, ext/session/session.c)
--FILE--
<?php
$prev = session_cache_limiter('public');
$current = session_cache_limiter();
session_set_cookie_params(['lifetime' => 3600, 'path' => '/app']);
$prev2 = session_cache_limiter('private');
$current2 = session_cache_limiter();
echo function_exists('session_cache_limiter') ? 'yes' : 'no', "\n";
echo $prev, "\n";
echo $current, "\n";
echo $prev2, "\n";
echo $current2, "\n";
--EXPECT--
yes
nocache
public
public
private
