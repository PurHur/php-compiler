#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Machine JSON for script/release-readiness.sh (--json).
 *
 * Gate rows are passed via indexed env vars (_RR_GATE_COUNT, _RR_GATE_NAME_0, …)
 * so empty messages and special characters do not misalign parallel arrays (#10531).
 */

$n = (int) (getenv('_RR_GATE_COUNT') ?: 0);
$gates = [];
for ($i = 0; $i < $n; ++$i) {
    $message = getenv("_RR_GATE_MESSAGE_{$i}");
    $gates[] = [
        'name' => (string) (getenv("_RR_GATE_NAME_{$i}") ?: ''),
        'status' => (string) (getenv("_RR_GATE_STATUS_{$i}") ?: 'unknown'),
        'message' => false !== $message ? (string) $message : '',
    ];
}

echo json_encode(
    [
        'user_release_ready' => getenv('_RR_READY') ?: 'no',
        'mode' => getenv('_RR_MODE') ?: 'quick',
        'gates' => $gates,
    ],
    JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
), "\n";
