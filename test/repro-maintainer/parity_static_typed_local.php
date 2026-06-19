<?php
declare(strict_types=1);

function inc(): void {
    static int $n = 0;
    $n++;
    echo $n, "\n";
}
inc();
inc();

function bad(): void {
    static string $s = 'ok';
    $s = 1; // TypeError after init
}
try { bad(); } catch (Throwable $e) { echo get_class($e), "\n"; }
