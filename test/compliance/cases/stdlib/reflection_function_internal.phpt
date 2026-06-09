--TEST--
Stdlib: ReflectionFunction on internal builtins strlen/array_map (#6678)
--FILE--
<?php
$r = new ReflectionFunction('strlen');
echo $r->getName(), "\n";
echo $r->isInternal() ? "internal\n" : "user\n";
echo $r->isUserDefined() ? "userdef\n" : "notuser\n";
echo $r->getExtensionName(), "\n";
echo count($r->getParameters()), "\n";

$r2 = new ReflectionFunction('array_map');
echo $r2->getName(), "\n";
echo $r2->isInternal() ? "internal\n" : "user\n";

function rf_internal_user(): void {}
$r3 = new ReflectionFunction('rf_internal_user');
echo $r3->getName(), "\n";
echo $r3->isInternal() ? "internal\n" : "user\n";
echo var_export($r3->getExtensionName(), true), "\n";
--EXPECT--
strlen
internal
notuser
Core
1
array_map
internal
rf_internal_user
user
false
