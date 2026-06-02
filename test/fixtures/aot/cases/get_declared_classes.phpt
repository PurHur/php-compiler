--TEST--
AOT: get_declared_classes() after class declarations (issue #3128)
--FILE--
<?php
class DeclaredClassC {}
$classes = get_declared_classes();
echo in_array('DeclaredClassC', $classes, true) ? '1' : '0';
echo "\n";
--EXPECT--
1
