--TEST--
Stdlib: class_uses() on enum case — trait map matches enum type (VM, #9774)
--FILE--
<?php
trait ClassUsesEnumTrait9774 {}
enum ClassUsesEnum9774 { case A; use ClassUsesEnumTrait9774; }

var_export(class_uses(ClassUsesEnum9774::class));
echo "\n";
var_export(class_uses(ClassUsesEnum9774::A));
echo "\n";
--EXPECT--
array (
  'ClassUsesEnumTrait9774' => 'ClassUsesEnumTrait9774',
)
array (
  'ClassUsesEnumTrait9774' => 'ClassUsesEnumTrait9774',
)
