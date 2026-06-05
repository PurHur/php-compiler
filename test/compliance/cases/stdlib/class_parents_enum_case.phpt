--TEST--
Stdlib: class_parents() on enum case — empty array not false (issue #6336)
--FILE--
<?php
enum ClassParentsEnum6336
{
    case A;
    case B;
}
var_export(class_parents(ClassParentsEnum6336::A));
echo "\n";
var_export(class_parents(ClassParentsEnum6336::B));
echo "\n";
--EXPECT--
array (
)
array (
)
