--TEST--
stdlib extension_loaded('curl'/'openssl') matches handle class visibility (#16750, #3325, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

echo 'curl_loaded=', (int) extension_loaded('curl'), "\n";
echo 'openssl_loaded=', (int) extension_loaded('openssl'), "\n";
echo 'curl_in_list=', (int) in_array('curl', get_loaded_extensions(), true), "\n";
echo 'openssl_in_list=', (int) in_array('openssl', get_loaded_extensions(), true), "\n";
echo 'CurlHandle=', (int) class_exists('CurlHandle', false), "\n";
echo 'OpenSSLCertificate=', (int) class_exists('OpenSSLCertificate', false), "\n";
--EXPECT--
curl_loaded=1
openssl_loaded=1
curl_in_list=1
openssl_in_list=1
CurlHandle=1
OpenSSLCertificate=1
