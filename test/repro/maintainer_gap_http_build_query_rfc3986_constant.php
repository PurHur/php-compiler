<?php

declare(strict_types=1);

$out = http_build_query(['a' => ['b' => 1, 'c' => 2]], '', '&', PHP_QUERY_RFC3986);
$expected = 'a%5Bb%5D=1&a%5Bc%5D=2';
if ($expected !== $out) {
    fwrite(STDERR, "http_build_query RFC3986: expected {$expected} got {$out}\n");
    exit(1);
}

$rfc1738 = http_build_query(['a' => ['x', 'y']], '', '&', PHP_QUERY_RFC1738);
if ('a%5B0%5D=x&a%5B1%5D=y' !== $rfc1738) {
    fwrite(STDERR, "http_build_query RFC1738: got {$rfc1738}\n");
    exit(1);
}

echo "ok\n";
