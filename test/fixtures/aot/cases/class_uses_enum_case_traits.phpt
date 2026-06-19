--TEST--
AOT class_uses() on enum case — trait map matches enum type (#9774)
--FILE--
<?php
trait AotClassUsesEnumTrait9774 {}
enum AotClassUsesEnum9774 { case A; use AotClassUsesEnumTrait9774; }

$byClass = class_uses(AotClassUsesEnum9774::class);
$byCase = class_uses(AotClassUsesEnum9774::A);
echo isset($byClass['AotClassUsesEnumTrait9774']) ? '1' : '0';
echo isset($byCase['AotClassUsesEnumTrait9774']) ? '1' : '0';
echo ($byClass['AotClassUsesEnumTrait9774'] ?? '') === ($byCase['AotClassUsesEnumTrait9774'] ?? '') ? '1' : '0';
--EXPECT--
111
