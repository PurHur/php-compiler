--TEST--
Stdlib: class_uses() on enum case — empty array not false (issue #6621)
--FILE--
<?php
enum ClassUsesEnum6621: string
{
    case A = 'a';
    case B = 'b';
}
var_export(class_uses(ClassUsesEnum6621::A));
echo "\n";
var_export(class_uses(ClassUsesEnum6621::B));
echo "\n";
--EXPECT--
array (
)
array (
)
