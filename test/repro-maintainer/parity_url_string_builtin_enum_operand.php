<?php
declare(strict_types=1);
enum E: string { case A = 'http://x'; }
foreach (['parse_url', 'urlencode', 'rawurlencode'] as $fn) {
    try {
        $fn(E::A);
        echo "{$fn}: uncaught\n";
    } catch (Throwable $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
