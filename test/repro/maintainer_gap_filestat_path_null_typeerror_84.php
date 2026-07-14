<?php

declare(strict_types=1);

$failed = 0;
foreach ([
    'touch' => static fn () => touch(null),
    'unlink' => static fn () => @unlink(null),
    'rename' => static fn () => rename(null, 'b'),
    'mkdir' => static fn () => @mkdir(null),
    'filesize' => static fn () => filesize(null),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: FAIL expected TypeError\n";
        ++$failed;
    } catch (TypeError $e) {
        echo "$label: ok\n";
    }
}
exit($failed > 0 ? 1 : 0);
