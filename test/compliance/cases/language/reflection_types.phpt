--TEST--
ReflectionNamedType / ReflectionUnionType / ReflectionIntersectionType (#3355)
--FILE--
<?php
class S {}
interface I {}
function demo(string|null $a, S&I $b): int|false {}
$rp = (new ReflectionFunction('demo'))->getParameters()[0];
$rt = (new ReflectionFunction('demo'))->getReturnType();
var_dump($rp->getType()::class);
var_dump($rt::class, (string) $rt);
$rp2 = (new ReflectionFunction('demo'))->getParameters()[1];
var_dump($rp2->getType()::class, (string) $rp2->getType());
?>
--EXPECT--
string(19) "ReflectionUnionType"
string(19) "ReflectionUnionType"
string(9) "int|false"
string(26) "ReflectionIntersectionType"
string(3) "S&I"
