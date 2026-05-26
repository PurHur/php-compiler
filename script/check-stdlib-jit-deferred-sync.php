#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard docs/stdlib-jit-audit.md and capabilities vs open stdlib JIT deferrals (#2465).
 *
 * Usage:
 *   php script/check-stdlib-jit-deferred-sync.php
 */

require __DIR__.'/stdlib-jit-deferred-sync-lib.php';

$root = dirname(__DIR__);
$errors = [];

$tracked = stdlib_jit_deferred_tracked();

$auditPath = $root.'/docs/stdlib-jit-audit.md';
$capabilitiesPath = $root.'/docs/capabilities.md';

if (!is_readable($auditPath)) {
    fwrite(STDERR, "check-stdlib-jit-deferred-sync: missing {$auditPath}\n");
    exit(1);
}

$auditText = (string) file_get_contents($auditPath);
$auditDeferred = stdlib_jit_deferred_parse_audit_deferred($auditText);
$auditDeferredCount = stdlib_jit_deferred_parse_audit_metric_count($auditText, 'Deferred (VM-only)');

if (null === $auditDeferredCount) {
    $errors[] = 'stdlib-jit-audit.md: missing Deferred (VM-only) metric row';
} elseif ($auditDeferredCount !== count($auditDeferred)) {
    $errors[] = "stdlib-jit-audit.md: metric Deferred {$auditDeferredCount} != parsed deferred list ".count($auditDeferred);
}

foreach (array_keys($tracked) as $builtin) {
    if (!in_array($builtin, $auditDeferred, true)) {
        $issue = $tracked[$builtin]['issue'];
        $errors[] = "stdlib-jit-audit.md: `{$builtin}` missing from Deferred section (open #{$issue}; update defer allowlist + php script/audit-stdlib-jit.php)";
    }
}

foreach ($auditDeferred as $builtin) {
    if (!isset($tracked[$builtin])) {
        $errors[] = "stdlib-jit-audit.md: `{$builtin}` listed deferred but not in script/stdlib-jit-deferred-lib.php allowlist";
    }
}

if ([] === $tracked && [] !== $auditDeferred) {
    $errors[] = 'stdlib-jit-deferred-lib.php allowlist empty but audit lists deferred builtins';
}

if (!is_readable($capabilitiesPath)) {
    $errors[] = 'missing docs/capabilities.md';
} else {
    $capabilitiesText = (string) file_get_contents($capabilitiesPath);
    foreach ($tracked as $builtin => $meta) {
        $row = stdlib_jit_deferred_parse_capabilities_row($capabilitiesText, $builtin);
        if (null === $row) {
            $errors[] = "capabilities.md: missing row for `{$builtin}`";
            continue;
        }
        if ($row['jit_yes'] && !stdlib_jit_deferred_capabilities_notes_ok($row['notes'], $meta['issue'])) {
            $errors[] = "capabilities.md: `{$builtin}` JIT=yes without deferral note (#{$meta['issue']})";
        }
    }
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-stdlib-jit-deferred-sync: {$err}\n");
    }
    fwrite(STDERR, "check-stdlib-jit-deferred-sync: FAILED — sync deferrals (#2465).\n");
    fwrite(STDERR, "  Regen audit: php script/audit-stdlib-jit.php\n");
    fwrite(STDERR, "  Regen matrix: php script/capability-matrix.php\n");
    fwrite(STDERR, "  Opt out:     STDLIB_JIT_DEFERRED_SYNC_GATE=0 ./script/ci-fast.sh\n");
    exit(1);
}

$summary = [] === $tracked
    ? '0 open deferrals'
    : implode(', ', array_map(
        static fn (string $name, array $meta): string => "`{$name}` (#{$meta['issue']})",
        array_keys($tracked),
        array_values($tracked)
    ));
fwrite(STDOUT, "check-stdlib-jit-deferred-sync: OK ({$summary}; audit deferred=".count($auditDeferred).").\n");
exit(0);
