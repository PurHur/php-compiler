--TEST--
stdlib extension_loaded('curl'/'openssl') — curl follows host Zend; openssl stays (#16750, #23953)
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
curl_loaded=0
openssl_loaded=1
curl_in_list=0
openssl_in_list=1
CurlHandle=0
OpenSSLCertificate=1
