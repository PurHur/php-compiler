<?php

declare(strict_types=1);

$cases = [
    'date(null)' => static fn () => date(null),
    'gmdate(null)' => static fn () => gmdate(null),
    'strtotime(null)' => static fn () => strtotime(null),
    'checkdate(null, 1, 2024)' => static fn () => checkdate(null, 1, 2024),
    'microtime(null)' => static fn () => microtime(null),
    'timezone_open(null)' => static fn () => timezone_open(null),
];

foreach ($cases as $label => $call) {
    try {
        $call();
        fwrite(STDERR, "$label: expected TypeError\n");
        exit(1);
    } catch (TypeError) {
        echo "$label: ok\n";
    }
}
