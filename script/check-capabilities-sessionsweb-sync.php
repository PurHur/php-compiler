#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard generated capability docs for 005-SessionsWeb + honest session AOT columns (issue #1947).
 *
 * Usage:
 *   php script/check-capabilities-sessionsweb-sync.php
 */

$root = dirname(__DIR__);
$capabilities = $root.'/docs/capabilities.md';
$syntax = $root.'/docs/capabilities-syntax.md';

$errors = [];

if (!is_readable($capabilities)) {
    fwrite(STDERR, "check-capabilities-sessionsweb-sync: missing {$capabilities}\n");
    exit(1);
}
if (!is_readable($syntax)) {
    fwrite(STDERR, "check-capabilities-sessionsweb-sync: missing {$syntax}\n");
    exit(1);
}

$capBody = (string) file_get_contents($capabilities);
$syntaxBody = (string) file_get_contents($syntax);

if (!str_contains($syntaxBody, '## Sessions reference (`examples/005-SessionsWeb`)')) {
    $errors[] = 'docs/capabilities-syntax.md: missing 005-SessionsWeb section (run: php script/capability-syntax.php)';
}

if (!preg_match('/\|\s*`005-SessionsWeb` reference app\s*\|/i', $syntaxBody)) {
    $errors[] = 'docs/capabilities-syntax.md: missing 005-SessionsWeb reference row';
}

foreach (['session_start', 'session_destroy', 'session_regenerate_id', 'session_write_close'] as $fn) {
    if (!preg_match(
        '/\|\s*`'.preg_quote($fn, '/').'`\s*\|\s*yes\s*\|\s*yes\s*\|\s*partial\s*\|/i',
        $capBody
    )) {
        $errors[] = "docs/capabilities.md: `{$fn}` must show VM yes, JIT yes, AOT partial (#1938)";
        continue;
    }
    if (!str_contains($capBody, '#1938')) {
        $errors[] = "docs/capabilities.md: `{$fn}` row must reference #1938";
    }
}

if (!preg_match('/\|\s*`session_start`\s*\/\s*`\$_SESSION` flash across requests\s*\|/i', $syntaxBody)) {
    $errors[] = 'docs/capabilities-syntax.md: missing session flash construct row';
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-capabilities-sessionsweb-sync: {$err}\n");
    }
    fwrite(STDERR, "check-capabilities-sessionsweb-sync: FAILED (regenerate capability docs; see #1947)\n");
    exit(1);
}

fwrite(STDOUT, "check-capabilities-sessionsweb-sync: OK\n");
exit(0);
