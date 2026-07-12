--TEST--
stdlib DNS_CAA and STREAM_CRYPTO_METHOD_TLS_* constants (#17782, ext/standard/dns.c streams.c)
--FILE--
<?php
declare(strict_types=1);

var_export(defined('DNS_CAA') && DNS_CAA === 8192);
echo "\n";
var_export(defined('STREAM_CRYPTO_METHOD_TLS_CLIENT') && STREAM_CRYPTO_METHOD_TLS_CLIENT === 121);
echo "\n";
var_export(defined('STREAM_CRYPTO_METHOD_TLS_SERVER') && STREAM_CRYPTO_METHOD_TLS_SERVER === 120);
echo "\n";
--EXPECT--
true
true
true
