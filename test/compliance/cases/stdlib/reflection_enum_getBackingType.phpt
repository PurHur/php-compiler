--TEST--
stdlib ReflectionEnum::getBackingType() backed and unit enums (#9886)
--FILE--
<?php
enum E: int { case A = 1; }
enum U { case A; case B; }
enum S: string { case X = 'x'; }
$backedInt = new ReflectionEnum(E::class);
$backedString = new ReflectionEnum(S::class);
$unit = new ReflectionEnum(U::class);
echo $backedInt->getBackingType()::class, "\n";
echo $backedInt->getBackingType()->getName(), "\n";
echo $backedInt->getBackingType()->isBuiltin() ? "1\n" : "0\n";
echo $backedString->getBackingType()->getName(), "\n";
echo null === $unit->getBackingType() ? "null\n" : "not-null\n";
echo $backedInt->isBacked() ? "1\n" : "0\n";
echo $unit->isBacked() ? "1\n" : "0\n";
--EXPECT--
ReflectionNamedType
int
1
string
null
1
0
