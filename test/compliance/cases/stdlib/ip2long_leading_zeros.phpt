--TEST--
stdlib ip2long() rejects leading-zero octets (#9300, php-src-strict)
--FILE--
<?php
var_export(ip2long('01.02.03.04'));
echo "\n";
var_export(ip2long('255.255.255.255'));
echo "\n";
var_export(ip2long('127.0.0.1'));
echo "\n";
--EXPECT--
false
4294967295
2130706433
