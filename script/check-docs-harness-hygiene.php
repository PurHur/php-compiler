#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard docs/README against raw `docker run -v "$(pwd):/compiler"` appearing
 * as a recommended command (issue #2485).
 *
 * Raw bind-mount docker runs are harmful on Runforge/harness hosts (empty tree).
 * This script fails if any doc file contains such a snippet outside a clearly
 * negated context (i.e. not preceded by "never", "do not", "avoid", "not use",
 * or appearing in a "Never on harness" table row).
 *
 * Usage:
 *   php script/check-docs-harness-hygiene.php          # check (exit 1 if found)
 *   php script/check-docs-harness-hygiene.php --list   # print offending lines
 *
 * CI gate (default on): DOCS_HARNESS_HYGIENE_GATE=1 in script/ci-defaults.env (#2485).
 * Opt-out: DOCS_HARNESS_HYGIENE_GATE=0 for doc-only iteration.
 */

$root = dirname(__DIR__);
$listOnly = in_array('--list', $argv, true);

$docFiles = [
    $root.'/README.md',
    $root.'/CONTRIBUTING.md',
    $root.'/docs/local-ci-matrix.md',
    $root.'/docs/bootstrap-selfhost.md',
    $root.'/docs/deploy-web-aot.md',
];

// Patterns that indicate a negated/warning context for the raw docker run line
$negationPatterns = [
    '/never/i',
    '/do\s*\**\s*not/i',
    '/avoid/i',
    '/not\s*\**\s*use/i',
    '/harmful/i',
    '/warning/i',
    '/empty.*tree/i',
    '/Never on harness/i',
];

$rawBindMountPattern = '/docker run.*-v.*["\']?\$\(pwd\)["\']?:\/compiler/';

$violations = [];

foreach ($docFiles as $file) {
    if (!is_readable($file)) {
        continue;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $lineNo => $line) {
        if (!preg_match($rawBindMountPattern, $line)) {
            continue;
        }
        // Check if this line itself, the previous line, or the next line has a negation context
        $context = $line;
        if ($lineNo > 0) {
            $context = $lines[$lineNo - 1].' '.$context;
        }
        if ($lineNo < count($lines) - 1) {
            $context .= ' '.$lines[$lineNo + 1];
        }
        $isNegated = false;
        foreach ($negationPatterns as $neg) {
            if (preg_match($neg, $context)) {
                $isNegated = true;
                break;
            }
        }
        if (!$isNegated) {
            $violations[] = sprintf('%s:%d: %s', $file, $lineNo + 1, trim($line));
        }
    }
}

if (empty($violations)) {
    echo "docs-harness-hygiene: OK — no raw docker run -v bind-mounts recommended in docs\n";
    exit(0);
}

foreach ($violations as $v) {
    echo "docs-harness-hygiene: VIOLATION: {$v}\n";
}

echo "\n";
echo "docs-harness-hygiene: FAILED — replace raw bind-mount docker run with harness-safe wrappers (#2485):\n";
echo "  make test-harness\n";
echo "  ./script/docker-ci-local.sh\n";
echo "  ./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && vendor/bin/phpunit <path>'\n";
echo "\nOr add a negation context ('never', 'do not use', etc.) before the raw snippet.\n";
exit(1);
