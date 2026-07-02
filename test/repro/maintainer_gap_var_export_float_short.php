<?php

declare(strict_types=1);

/**
 * Maintainer repro: var_export() near-round float shortening (#15044).
 *
 * php-src: ext/standard/var.c — php_var_export double formatting.
 */

$value = round(1.55, 1, PHP_ROUND_HALF_UP);
$fromRound = var_export($value, true);
$fromLiteral = var_export(1.6000000000000001, true);

if ('1.6' !== $fromRound) {
    echo 'FAIL round var_export=' . $fromRound;
    exit(1);
}
if ('1.6' !== $fromLiteral) {
    echo 'FAIL literal var_export=' . $fromLiteral;
    exit(1);
}

echo "ok\n";
