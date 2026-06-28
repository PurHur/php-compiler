<?php

declare(strict_types=1);

/**
 * Maintainer repro: stream_get_meta_data()['mode'] must echo user fopen mode (#13021).
 *
 * php-src: main/streams/streams.c — php_stream_get_meta_data mode field
 */

$path = tempnam(sys_get_temp_dir(), 'meta');
if (false === $path) {
    echo "fail: tempnam\n";
    exit(1);
}

$checks = [
    'r+' => 'r+',
    'w+' => 'w+',
    'rb' => 'rb',
    'r+b' => 'r+b',
];

foreach ($checks as $openMode => $expected) {
    $f = fopen($path, $openMode);
    if (false === $f) {
        echo "fail: fopen($openMode)\n";
        exit(1);
    }
    $reported = stream_get_meta_data($f)['mode'];
    fclose($f);
    if ($reported !== $expected) {
        echo "fail: fopen($openMode) mode=$reported expected=$expected\n";
        exit(1);
    }
}

unlink($path);
echo "ok\n";
