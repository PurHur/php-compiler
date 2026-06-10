<?php

declare(strict_types=1);

echo 'ParseUrl enum: ', enum_exists('ParseUrl', false) ? 'yes' : 'no', "\n";
if (!enum_exists('ParseUrl', false)) {
    fwrite(STDERR, "FAIL: ParseUrl enum missing\n");
    exit(1);
}
$url = 'http://user:pass@example.com:8080/path?q=1#frag';
echo parse_url($url, ParseUrl::Host), "\n";
echo parse_url($url, component: ParseUrl::Path), "\n";
echo parse_url($url, ParseUrl::Port), "\n";
echo parse_url($url, PHP_URL_USER), "\n";
