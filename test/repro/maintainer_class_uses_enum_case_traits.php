<?php

/**
 * class_uses() on enum case with traits — issue #9774.
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(class_uses)
 */
trait MaintainerClassUsesEnumTrait9774 {}
enum MaintainerClassUsesEnum9774 { case A; use MaintainerClassUsesEnumTrait9774; }

var_export(class_uses(MaintainerClassUsesEnum9774::class));
echo "\n";
var_export(class_uses(MaintainerClassUsesEnum9774::A));
echo "\n";
