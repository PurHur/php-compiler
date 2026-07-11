--TEST--
stdlib session_set_cookie_params() / session_get_cookie_params() array form (#9982, ext/session/session.c)
--FILE--
<?php
session_set_cookie_params(['lifetime' => 3600, 'path' => '/app']);
$params = session_get_cookie_params();
session_set_cookie_params(120, '/legacy');
$params2 = session_get_cookie_params();
echo function_exists('session_set_cookie_params') ? 'yes' : 'no', "\n";
echo function_exists('session_get_cookie_params') ? 'yes' : 'no', "\n";
echo $params['lifetime'], "\n";
echo $params['path'], "\n";
echo $params['domain'] === '' ? 'empty' : $params['domain'], "\n";
echo $params['secure'] ? 'secure' : 'nosecure', "\n";
echo $params2['lifetime'], "\n";
echo $params2['path'], "\n";
--EXPECT--
yes
yes
3600
/app
empty
nosecure
120
/legacy
