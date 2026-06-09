<?php
enum E: string { case A = 'a'; }
$r = new ReflectionEnum(E::class);
echo $r->getCase('A')->getName(), "\n";
try {
    $r->getCase('Z');
} catch (ReflectionException $e) {
    echo $e->getMessage(), "\n";
}
