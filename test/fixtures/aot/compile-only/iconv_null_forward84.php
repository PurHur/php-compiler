<?php
declare(strict_types=1);
// Compile-only (#18993): iconv() null encoding operands TypeError on 8.4 forward profile.
foreach ([
    static fn () => iconv(null, 'UTF-8', 'x'),
    static fn () => iconv('UTF-8', null, 'x'),
] as $factory) {
    try {
        $factory();
    } catch (TypeError) {
        // expected
    }
}
