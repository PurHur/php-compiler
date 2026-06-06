<?php
enum E: string { case A = 'x'; }
foreach (['highlight_string', 'highlight_file', 'show_source'] as $fn) {
    try {
        $fn(E::A);
        echo $fn, ": uncaught\n";
    } catch (Throwable $e) {
        echo $fn, ': ', $e::class, ': ', $e->getMessage(), "\n";
    }
}
