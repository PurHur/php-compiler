<?php
enum E: int { case A = 1; }
$r = new ReflectionEnum(E::class);
$type = $r->getBackingType();
echo $type::class, "\n";
echo $type->getName(), "\n";
var_export($r->isBacked());
echo "\n";
