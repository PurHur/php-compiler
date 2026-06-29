<?php

$cases = [
    ['http://[::1]/', '[::1]', null],
    ['http://[::1]:8080/path', '[::1]', 8080],
    ['http://[2001:db8::1]/', '[2001:db8::1]', null],
    ['ftp://user:pass@[::1]:21/', '[::1]', 21],
];

foreach ($cases as [$url, $expectedHost, $expectedPort]) {
    $host = parse_url($url, PHP_URL_HOST);
    if ($host !== $expectedHost) {
        echo 'fail:host:', $url, ':', var_export($host, true), "\n";
        exit(1);
    }
    $port = parse_url($url, PHP_URL_PORT);
    if ($port !== $expectedPort) {
        echo 'fail:port:', $url, ':', var_export($port, true), "\n";
        exit(1);
    }
    echo 'ok:', $url, "\n";
}
