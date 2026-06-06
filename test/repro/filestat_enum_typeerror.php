<?php
declare(strict_types=1);

enum PathE: string { case A = '/tmp'; }

foreach (['stat', 'lstat', 'fileperms', 'fileinode', 'filegroup', 'fileowner', 'is_link'] as $fn) {
    try {
        $fn(PathE::A);
        echo "{$fn} ok\n";
    } catch (Throwable $e) {
        echo "{$fn} " . $e::class . ': ' . $e->getMessage() . "\n";
    }
}
