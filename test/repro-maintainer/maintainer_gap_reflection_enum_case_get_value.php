<?php
declare(strict_types=1);

enum E: int { case A = 1; }
$c = (new ReflectionEnum(E::class))->getCase('A');
var_export($c->getValue());
echo "\n";
var_export($c->getBackingValue());
echo "\n";
