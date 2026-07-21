<?php

declare(strict_types=1);

/**
 * Repro #21827 — mysqli_refresh / mysqli_get_connection_stats registration.
 */
foreach (['mysqli_refresh', 'mysqli_get_connection_stats'] as $fn) {
    echo $fn, ':', function_exists($fn) ? 'yes' : 'no', "\n";
}
