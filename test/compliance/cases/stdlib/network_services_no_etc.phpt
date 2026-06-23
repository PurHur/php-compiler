--TEST--
stdlib getprotobyname()/getservbyname() return false without /etc databases (#10866, ext/standard/network.c)
--FILE--
<?php
var_export(getprotobyname('tcp'));
echo "\n";
var_export(getprotobyname('udp'));
echo "\n";
var_export(getservbyname('http', 'tcp'));
echo "\n";
var_export(getprotobynumber(6));
echo "\n";
--EXPECT--
false
false
false
false
