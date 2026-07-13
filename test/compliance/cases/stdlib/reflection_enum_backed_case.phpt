--TEST--
Stdlib: ReflectionEnumBackedCase — getCases/getCase/getBackingValue (php_reflection.c, #5675)
--FILE--
<?php
enum Status: int { case Active = 1; case Archived = 2; }
enum Pure { case A; }

$backed = (new ReflectionEnum(Status::class))->getCases()[0];
echo $backed::class, "\n";
echo $backed->getBackingValue(), "\n";

$viaGetCase = (new ReflectionEnum(Status::class))->getCase('Archived');
echo $viaGetCase::class, "\n";
echo $viaGetCase->getBackingValue(), "\n";

$unit = (new ReflectionEnum(Pure::class))->getCases()[0];
echo $unit::class, "\n";
--EXPECT--
ReflectionEnumBackedCase
1
ReflectionEnumBackedCase
2
ReflectionEnumUnitCase
