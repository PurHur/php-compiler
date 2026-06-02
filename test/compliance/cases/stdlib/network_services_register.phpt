--TEST--
stdlib getprotobyname() / getservbyname() registered
--FILE--
<?php
echo function_exists('getprotobyname') ? 'proto_yes' : 'proto_no', "\n";
echo function_exists('getservbyname') ? 'serv_yes' : 'serv_no', "\n";
--EXPECT--
proto_yes
serv_yes
