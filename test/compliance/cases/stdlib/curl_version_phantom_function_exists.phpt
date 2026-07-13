--TEST--
stdlib curl_version function_exists false without ext/curl (#18554, ext/curl/interface.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('curl'), "\n";
echo 'version=', (int) function_exists('curl_version'), "\n";
echo 'init=', (int) function_exists('curl_init'), "\n";
--EXPECT--
loaded=0
version=0
init=0
