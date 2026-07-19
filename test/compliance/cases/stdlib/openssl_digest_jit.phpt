--TEST--
stdlib openssl_digest() — sha256 hex digest JIT (#21081, re-#6228)
--JIT--
--FILE--
<?php
echo function_exists('openssl_digest') ? "exists\n" : "missing\n";
$digest = openssl_digest('data', 'sha256');
echo is_string($digest) ? $digest : 'fail';
echo "\n";
var_dump(openssl_digest('data', 'not-a-digest'));
--EXPECTF--
PHP Warning:  openssl_digest(): Unknown digest algorithm in %s on line %d
exists
3a6eb0790f39ac87c94f3856b2dd2c5d110e6811602261a9a923d3bb23adc8b7
bool(false)
