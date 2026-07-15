--TEST--
ReflectionEnumUnitCase/ReflectionEnumBackedCase::isBacked() — PHP 8.4 enum case API (#5675, #18648, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
enum Status: int { case Active = 1; case Archived = 2; }
enum Pure { case A; }
$backed = (new ReflectionEnum(Status::class))->getCases()[0];
$unit = (new ReflectionEnum(Pure::class))->getCases()[0];
echo $backed->isBacked() ? "backed\n" : "unit\n";
echo $unit->isBacked() ? "backed\n" : "unit\n";
--EXPECT--
backed
unit
