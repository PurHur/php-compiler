<?php

declare(strict_types=1);

/**
 * Issue #26143 — PROFILE=8.4 bcadd/bcsub/bcmul/bcdiv/bcmod have no $rounding_mode
 * (php-src ext/bcmath/bcmath.stub.php; only bcround takes RoundingMode).
 */

$fns = ['bcadd', 'bcsub', 'bcmul', 'bcdiv', 'bcmod'];
foreach ($fns as $fn) {
    $r = new ReflectionFunction($fn);
    $names = array_map(static fn (ReflectionParameter $p): string => $p->getName(), $r->getParameters());
    echo $fn, ' n=', $r->getNumberOfParameters(), ' ', implode(',', $names), "\n";
}

try {
    echo bcadd('1.4', '1.4', 0, RoundingMode::HalfEven), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    echo bcadd('1', '1', rounding_mode: RoundingMode::HalfAwayFromZero), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

echo 'bcround=', bcround('1.55', 1, RoundingMode::HalfAwayFromZero), "\n";
