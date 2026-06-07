<?php
declare(strict_types=1);
// Compile-only (#5860): parse_url()/urlencode()/rawurlencode() must lower enum-case TypeError guards for AOT.
enum E: string { case A = 'http://x'; }
foreach (['parse_url', 'urlencode', 'rawurlencode'] as $fn) {
    try {
        $fn(E::A);
        echo "{$fn} uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
