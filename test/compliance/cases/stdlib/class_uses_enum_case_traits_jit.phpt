--TEST--
Stdlib: class_uses() on enum case — trait map matches enum type (JIT, #9774)
--FILE--
<?php
trait JitClassUsesEnumTrait9774 {}
enum JitClassUsesEnum9774 { case A; use JitClassUsesEnumTrait9774; }

var_export(class_uses(JitClassUsesEnum9774::class));
echo "\n";
var_export(class_uses(JitClassUsesEnum9774::A));
echo "\n";
--EXPECT--
array (
  'JitClassUsesEnumTrait9774' => 'JitClassUsesEnumTrait9774',
)
array (
  'JitClassUsesEnumTrait9774' => 'JitClassUsesEnumTrait9774',
)
