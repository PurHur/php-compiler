<?php
declare(strict_types=1);
// Compile-only (#6220): lcfirst()/ucfirst() must lower enum-case TypeError guards for AOT.
enum E: string { case X = 'hello'; }
foreach (['lcfirst', 'ucfirst'] as $fn) {
    try {
        $fn(E::X);
        echo "{$fn} uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
