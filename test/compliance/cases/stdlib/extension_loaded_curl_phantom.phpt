--TEST--
stdlib extension_loaded('curl') / curl_init withheld without host ext/curl (#23953)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('curl'), "\n";
echo 'in_list=', (int) in_array('curl', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) (false !== get_extension_funcs('curl')), "\n";
echo 'init=', (int) function_exists('curl_init'), "\n";
echo 'version=', (int) function_exists('curl_version'), "\n";
echo 'CurlHandle=', (int) class_exists('CurlHandle', false), "\n";
echo 'CURLFile=', (int) class_exists('CURLFile', false), "\n";
echo 'CURLOPT_URL=', (int) defined('CURLOPT_URL'), "\n";
?>
--EXPECT--
loaded=0
in_list=0
funcs=0
init=0
version=0
CurlHandle=0
CURLFile=0
CURLOPT_URL=0
