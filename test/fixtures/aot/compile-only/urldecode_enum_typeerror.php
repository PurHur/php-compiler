<?php
declare(strict_types=1);
// Compile-only (#6258): urldecode()/rawurldecode() must lower enum-case TypeError guards for AOT.
enum E: string { case A = 'hello%20world'; }
foreach (['urldecode', 'rawurldecode'] as $fn) {
    try {
        $fn(E::A);
        echo "{$fn} uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
