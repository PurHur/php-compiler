--TEST--
ReflectionFunction::createFromFunction() static factory forward profile (#6994)
--FILE--
<?php
declare(strict_types=1);

function rf_create_from_user(int $x): int {
    return $x;
}

$r = ReflectionFunction::createFromFunction('rf_create_from_user');
echo $r->getName(), "\n";
echo count($r->getParameters()), "\n";
echo $r->isUserDefined() ? "user\n" : "internal\n";

$r2 = ReflectionFunction::createFromFunction('strlen');
echo $r2->getName(), "\n";
echo $r2->isInternal() ? "internal\n" : "user\n";
echo count($r2->getParameters()), "\n";

$r3 = new ReflectionFunction('rf_create_from_user');
echo $r3->getName() === $r->getName() ? "ctor-match\n" : "ctor-mismatch\n";
--EXPECT--
rf_create_from_user
1
user
strlen
internal
1
ctor-match
