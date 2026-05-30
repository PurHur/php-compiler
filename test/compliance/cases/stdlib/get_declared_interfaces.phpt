--TEST--
Stdlib: get_declared_interfaces() lists user interfaces only (VM, #3176)
--FILE--
<?php
interface DeclaredIfaceA {}
interface DeclaredIfaceB {}
trait DeclaredTraitT {}
class DeclaredClassC {}
$ifaces = get_declared_interfaces();
echo count($ifaces) >= 2 ? '1' : '0';
echo in_array('DeclaredIfaceA', $ifaces, true) ? '1' : '0';
echo in_array('DeclaredIfaceB', $ifaces, true) ? '1' : '0';
echo in_array('DeclaredClassC', $ifaces, true) ? '1' : '0';
echo in_array('DeclaredTraitT', $ifaces, true) ? '1' : '0';
echo "\n";
--EXPECT--
11100
