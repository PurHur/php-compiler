<?php
declare(strict_types=1);
// Compile-only (#5735): filesystem path builtins must lower enum-case TypeError guards for AOT.
enum E: string { case A = 'x'; }

foreach (['file_get_contents', 'is_file', 'unlink', 'mkdir'] as $fn) {
    try {
        $fn(E::A);
        echo "{$fn} uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
foreach (['copy', 'rename'] as $fn) {
    try {
        $fn(E::A, '/tmp/y');
        echo "{$fn} uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
