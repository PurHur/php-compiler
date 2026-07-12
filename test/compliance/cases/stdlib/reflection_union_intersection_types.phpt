--TEST--
ReflectionUnionType / ReflectionIntersectionType::getTypes() on class methods (#4650)
--FILE--
<?php
declare(strict_types=1);

class C {
    public function m(string|int $x, Countable&Traversable $y): void {}
}

$rm = new ReflectionMethod(C::class, 'm');
$union = $rm->getParameters()[0]->getType();
$inter = $rm->getParameters()[1]->getType();

echo get_class($union), "\n";
echo get_class($inter), "\n";
echo method_exists($union, 'getTypes') ? "union-method-ok\n" : "union-method-bad\n";
echo method_exists($inter, 'getTypes') ? "inter-method-ok\n" : "inter-method-bad\n";

$unionTypes = $union->getTypes();
echo count($unionTypes), "\n";
echo $unionTypes[0]::class, "\n";
echo $unionTypes[0]->getName(), "\n";
echo $unionTypes[1]->getName(), "\n";

$interTypes = $inter->getTypes();
echo count($interTypes), "\n";
echo $interTypes[0]->getName(), "\n";
echo $interTypes[1]->getName(), "\n";
--EXPECT--
ReflectionUnionType
ReflectionIntersectionType
union-method-ok
inter-method-ok
2
ReflectionNamedType
string
int
2
Countable
Traversable
