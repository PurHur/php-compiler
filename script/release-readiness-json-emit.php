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

$honestCompile = null;
$honestJson = getenv('_RR_HONEST_COMPILE_JSON');
if (false !== $honestJson && '' !== $honestJson) {
    $decoded = json_decode($honestJson, true);
    if (is_array($decoded)) {
        $honestCompile = $decoded;
    }
}

$gen0Provenance = null;
$gen0Json = getenv('_RR_GEN0_PROVENANCE_JSON');
if (false !== $gen0Json && '' !== $gen0Json) {
    $decoded = json_decode($gen0Json, true);
    if (is_array($decoded)) {
        $gen0Provenance = $decoded;
    }
}

$payload = [
    'user_release_ready' => getenv('_RR_READY') ?: 'no',
    'mode' => getenv('_RR_MODE') ?: 'quick',
    'gates' => $gates,
];
if (null !== $honestCompile) {
    $payload['honest_compile'] = $honestCompile;
}
if (null !== $gen0Provenance) {
    $payload['gen0_provenance'] = $gen0Provenance;
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
