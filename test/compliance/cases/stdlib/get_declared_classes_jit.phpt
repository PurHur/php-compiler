--TEST--
Stdlib: get_declared_classes() lists classes not traits/interfaces (JIT, #3128)
--JIT--
--FILE--
<?php
interface DeclaredIfaceA {}
trait DeclaredTraitT {}
class DeclaredClassC {}
$classes = get_declared_classes();
echo count($classes) >= 1 ? '1' : '0';
echo in_array('DeclaredClassC', $classes, true) ? '1' : '0';
echo in_array('DeclaredIfaceA', $classes, true) ? '1' : '0';
echo in_array('DeclaredTraitT', $classes, true) ? '1' : '0';
echo "\n";
--EXPECT--
1100
