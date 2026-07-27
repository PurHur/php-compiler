--TEST--
AOT net_get_interfaces() — returns array (#6106, #23715)
--FILE--
<?php
$ifaces = net_get_interfaces();
echo is_array($ifaces) ? "array\n" : "not_array\n";
--EXPECT--
array
