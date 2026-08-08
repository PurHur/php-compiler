<?php

declare(strict_types=1);

// Compile-only (#28536 / re-#7260): ParseUrl phantom absent; PHP_URL_* ints through AOT.
$url = 'http://user:pass@example.com:8080/path?q=1#frag';
echo enum_exists('ParseUrl', false) ? "yes\n" : "no\n";
echo parse_url($url, PHP_URL_HOST), "\n";
echo parse_url($url, component: PHP_URL_PATH), "\n";
echo parse_url($url, PHP_URL_PORT), "\n";
