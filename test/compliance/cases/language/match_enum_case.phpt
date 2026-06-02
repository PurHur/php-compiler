--TEST--
match enum case arms use === identity, not backed scalar (issue #4274)
--FILE--
<?php
enum Color: string { case Red = 'r'; case Blue = 'b'; }
echo match (Color::Red) {
    'r' => 'string_arm',
    Color::Red => 'enum_arm',
    default => 'other',
}, "\n";
enum Size { case S; case M; }
echo match (Size::S) {
    Size::M => 'm',
    Size::S => 's',
    default => '?',
}, "\n";
--EXPECT--
enum_arm
s
