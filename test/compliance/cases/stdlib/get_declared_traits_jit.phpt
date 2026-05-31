--TEST--
Stdlib: get_declared_traits() lists traits only (JIT, #3128)
--JIT--
--FILE--
<?php
interface DeclaredIfaceA {}
trait DeclaredTraitT {}
class DeclaredClassC {}
$traits = get_declared_traits();
echo count($traits) >= 1 ? '1' : '0';
echo in_array('DeclaredTraitT', $traits, true) ? '1' : '0';
echo in_array('DeclaredClassC', $traits, true) ? '1' : '0';
echo in_array('DeclaredIfaceA', $traits, true) ? '1' : '0';
echo "\n";
--EXPECT--
1100
