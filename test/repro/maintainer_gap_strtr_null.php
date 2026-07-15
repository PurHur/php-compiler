<?php

declare(strict_types=1);

foreach ([
    'two-string' => static fn () => strtr(null, 'ab', 'cd'),
    'array' => static fn () => strtr(null, ['a' => 'b']),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo "$label: ".$e->getMessage()."\n";
    }
}
