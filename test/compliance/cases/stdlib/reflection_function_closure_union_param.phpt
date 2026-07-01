--TEST--
ReflectionFunction on Closure — union/intersection parameter types (#11545, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

$union = function (string|int $x) {};
$unionType = (new ReflectionFunction($union))->getParameters()[0]->getType();
echo $unionType instanceof ReflectionUnionType ? "union-ok\n" : "union-bad\n";
echo $unionType->getTypes()[0]->getName(), '|', $unionType->getTypes()[1]->getName(), "\n";

$inter = function (Iterator&Countable $x) {};
$interType = (new ReflectionFunction($inter))->getParameters()[0]->getType();
echo $interType instanceof ReflectionIntersectionType ? "intersection-ok\n" : "intersection-bad\n";
$members = $interType->getTypes();
echo count($members), "\n";
echo $members[0]->getName(), '&', $members[1]->getName(), "\n";
--EXPECT--
union-ok
string|int
intersection-ok
2
Iterator&Countable
