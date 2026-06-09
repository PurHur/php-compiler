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

function user_fn(): void {}
$r3 = new ReflectionFunction('user_fn');
echo $r3->getName(), "\n";
echo $r3->isInternal() ? "internal\n" : "user\n";
echo var_export($r3->getExtensionName(), true), "\n";
