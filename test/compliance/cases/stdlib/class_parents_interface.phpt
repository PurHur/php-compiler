--TEST--
Stdlib: class_parents() on interface — empty array not false (issue #5249)
--FILE--
<?php
interface ClassParentsIface5249 {}
interface ClassParentsIfaceExt5249 extends ClassParentsIface5249 {}
var_export(class_parents('ClassParentsIface5249'));
echo "\n";
var_export(class_parents('ClassParentsIfaceExt5249'));
echo "\n";
var_export(class_parents(ClassParentsIface5249::class));
echo "\n";
--EXPECT--
array (
)
array (
)
array (
)
