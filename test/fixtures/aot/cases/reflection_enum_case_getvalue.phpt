--TEST--
AOT: ReflectionEnum::getBackingType / case getValue() (#27515, php_reflection.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$r = new ReflectionEnum(E::class);
echo $r->isBacked() ? "backed\n" : "unit\n";
echo $r->getBackingType()->getName(), "\n";
echo $r->getCase("B")->getValue()->value, "\n";

enum U { case Alpha; }
echo (new ReflectionEnum(U::class))->getCase("Alpha")->getValue()->name, "\n";
--EXPECT--
backed
int
2
Alpha
