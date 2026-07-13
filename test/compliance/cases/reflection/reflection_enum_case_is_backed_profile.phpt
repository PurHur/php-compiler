--TEST--
ReflectionEnumUnitCase/ReflectionEnumBackedCase::isBacked() phantom on 8.2 reference profile (#18648, ext/reflection/php_reflection.c)
--FILE--
<?php
enum Status: int { case Active = 1; case Archived = 2; }
enum Pure { case A; }
$backed = (new ReflectionEnum(Status::class))->getCases()[0];
$unit = (new ReflectionEnum(Pure::class))->getCases()[0];
echo 'backed=', method_exists($backed, 'isBacked') ? 'yes' : 'no', "\n";
echo 'unit=', method_exists($unit, 'isBacked') ? 'yes' : 'no', "\n";
--EXPECT--
backed=no
unit=no
