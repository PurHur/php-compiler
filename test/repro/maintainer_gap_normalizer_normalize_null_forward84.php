<?php

declare(strict_types=1);

// Issue #21063 — PROFILE=8.4: normalizer_normalize(null) / Normalizer::normalize(null) → TypeError.
putenv('PHP_COMPILER_PROFILE=8.4');

foreach ([
    'proc' => static fn () => normalizer_normalize(null),
    'static' => static fn () => Normalizer::normalize(null),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, '=', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $name, '=', get_class($e), "\n";
    }
}
