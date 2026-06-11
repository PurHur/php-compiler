--TEST--
AOT net_get_interfaces() — loopback interface present (#6106)
--FILE--
<?php
$ifaces = net_get_interfaces();
echo is_array($ifaces) ? "array\n" : "not_array\n";
--EXPECT--
array
