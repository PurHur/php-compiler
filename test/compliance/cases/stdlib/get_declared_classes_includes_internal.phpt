--TEST--
stdlib get_declared_classes() — includes CE_INTERNAL builtin classes (#11813, basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

interface DeclaredIfaceProbe {}
trait DeclaredTraitProbe {}
class DeclaredUserClassProbe {}

$classes = get_declared_classes();
echo in_array('stdClass', $classes, true) ? '1' : '0';
echo in_array('Exception', $classes, true) ? '1' : '0';
echo in_array('Closure', $classes, true) ? '1' : '0';
echo in_array('WeakMap', $classes, true) ? '1' : '0';
echo in_array('DeclaredUserClassProbe', $classes, true) ? '1' : '0';
echo in_array('DeclaredIfaceProbe', $classes, true) ? '1' : '0';
echo in_array('DeclaredTraitProbe', $classes, true) ? '1' : '0';
echo "\n";
--EXPECT--
1111100
