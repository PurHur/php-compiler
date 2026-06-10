<?php

declare(strict_types=1);

// Compile-only (#7260): parse_url() must lower ParseUrl enum component at AOT compile time.
$url = 'http://user:pass@example.com:8080/path?q=1#frag';
echo parse_url($url, ParseUrl::Host), "\n";
echo parse_url($url, component: ParseUrl::Path), "\n";
echo parse_url($url, ParseUrl::Port), "\n";
