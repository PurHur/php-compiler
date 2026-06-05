--TEST--
Stdlib: get_declared_classes() includes declared enums (VM, #5664, basic_functions.c)
--FILE--
<?php
enum DeclaredEnumE: int { case A = 1; }
interface DeclaredIfaceA {}
trait DeclaredTraitT {}
class DeclaredClassC {}
$classes = get_declared_classes();
echo in_array('DeclaredEnumE', $classes, true) ? '1' : '0';
echo in_array('DeclaredClassC', $classes, true) ? '1' : '0';
echo in_array('DeclaredIfaceA', $classes, true) ? '1' : '0';
echo in_array('DeclaredTraitT', $classes, true) ? '1' : '0';
echo enum_exists('DeclaredEnumE') ? '1' : '0';
echo "\n";
--EXPECT--
11001
