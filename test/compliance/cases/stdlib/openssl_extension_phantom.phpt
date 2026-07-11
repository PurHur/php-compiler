--TEST--
stdlib openssl extension_loaded withheld until openssl_x509_parse() (#11859, ext/openssl/openssl.c)
--FILE--
<?php
declare(strict_types=1);
echo extension_loaded('openssl') ? "loaded\n" : "withheld\n";
echo function_exists('openssl_x509_parse') ? "parse_yes\n" : "parse_no\n";
echo function_exists('openssl_encrypt') ? "encrypt_yes\n" : "encrypt_no\n";
--EXPECT--
loaded
parse_yes
encrypt_yes
