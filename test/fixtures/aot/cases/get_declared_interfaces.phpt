--TEST--
AOT: get_declared_interfaces() after interface declarations (issue #3176)
--FILE--
<?php
interface AotDeclaredIface {}
class AotDeclaredClass {}
$ifaces = get_declared_interfaces();
echo in_array('AotDeclaredIface', $ifaces, true) ? '1' : '0';
echo in_array('AotDeclaredClass', $ifaces, true) ? '1' : '0';
--EXPECT--
10
