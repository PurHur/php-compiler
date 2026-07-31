<?php
// Repro #25940 — ReflectionEnum::getCases()/getCase() after case-sensitive enum keys.
enum E: int { case A = 1; }
$r = new ReflectionEnum(E::class);
echo 'has=', var_export($r->hasCase('A'), true), "\n";
foreach ($r->getCases() as $c) {
    echo 'case:', $c->getName(), "\n";
}
echo 'getCase:', $r->getCase('A')->getName(), "\n";
enum U { case X; }
echo 'unit:', (new ReflectionEnum(U::class))->getCase('X')->getName(), "\n";
try {
    $r->getCase('Z');
    echo "missing_ok\n";
} catch (ReflectionException $e) {
    echo 'missing:', $e->getMessage(), "\n";
}
