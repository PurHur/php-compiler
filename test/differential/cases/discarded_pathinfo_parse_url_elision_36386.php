<?php

declare(strict_types=1);

/**
 * Discarded pathinfo / parse_url must not change observable output (#36386).
 *
 * php-src: ext/standard/basic_functions.c / file.c (pathinfo),
 * ext/standard/url.c (parse_url)
 */

function work(string $p, string $u): string
{
    pathinfo($p);
    parse_url($u);

    $ext = (string) pathinfo('/foo/bar.txt', PATHINFO_EXTENSION);
    $host = (string) parse_url('http://example.com/x', PHP_URL_HOST);
    $base = (string) pathinfo('/a/b.c', PATHINFO_BASENAME);
    $scheme = (string) parse_url('https://x.test/y', PHP_URL_SCHEME);

    return $ext.'|'.$host.'|'.$base.'|'.$scheme;
}

echo work('/a/b.txt', 'http://example.com/x'), "\n";
echo work('/z/w.php', 'https://x.test/y'), "\n";
