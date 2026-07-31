--TEST--
stdlib curl_version function_exists with loaded ext/curl (#18554, #3325, ext/curl/interface.c)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('curl'), "\n";
echo 'version=', (int) function_exists('curl_version'), "\n";
echo 'init=', (int) function_exists('curl_init'), "\n";
--EXPECT--
loaded=1
version=1
init=1
