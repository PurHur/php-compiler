--TEST--
AOT class_uses() on enum case — empty array not false (issue #6621)
--FILE--
<?php
enum AotClassUsesEnum6621: string
{
    case A = 'a';
    case B = 'b';
}
$u1 = class_uses(AotClassUsesEnum6621::A);
$u2 = class_uses(AotClassUsesEnum6621::B);
echo is_array($u1) && 0 === count($u1) ? '1' : '0';
echo is_array($u2) && 0 === count($u2) ? '1' : '0';
--EXPECT--
11
