#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard generated capability docs for 005-SessionsWeb + honest session AOT columns (issue #1947, #1976).
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
        '/\|\s*`'.preg_quote($fn, '/').'`\s*\|\s*yes\s*\|\s*yes\s*\|\s*yes\s*\|/i',
        $capBody
    )) {
        $errors[] = "docs/capabilities.md: `{$fn}` must show VM yes, JIT yes, AOT yes (#1976)";
    }
}

if (!str_contains($capBody, 'SESSIONS_WEB_AOT_SMOKE_GATE') || !str_contains($capBody, 'SESSIONS_WEB_DEPLOY_SMOKE_GATE')) {
    $errors[] = 'docs/capabilities.md: session_* notes must mention opt-in SESSIONS_WEB_* gates (#1976)';
}

if (!preg_match('/\|\s*`session_start`\s*\/\s*`\$_SESSION` flash across requests\s*\|/i', $syntaxBody)) {
    $errors[] = 'docs/capabilities-syntax.md: missing session flash construct row';
}

if (!preg_match(
    '/\|\s*`005-SessionsWeb` reference app\s*\|\s*yes\s*\|\s*yes\s*\|\s*yes\s*\|/i',
    $syntaxBody
)) {
    $errors[] = 'docs/capabilities-syntax.md: 005-SessionsWeb reference app must show AOT yes (#1976)';
}

if (!preg_match(
    '/\|\s*`session_start`\s*\/\s*`\$_SESSION` flash across requests\s*\|\s*yes\s*\|\s*yes\s*\|\s*yes\s*\|/i',
    $syntaxBody
)) {
    $errors[] = 'docs/capabilities-syntax.md: session flash construct must show AOT yes (#1976)';
}

if (!str_contains($syntaxBody, 'SESSIONS_WEB_AOT_SMOKE_GATE') || !str_contains($syntaxBody, 'SESSIONS_WEB_DEPLOY_SMOKE_GATE')) {
    $errors[] = 'docs/capabilities-syntax.md: footer must mention SESSIONS_WEB_AOT_SMOKE_GATE and SESSIONS_WEB_DEPLOY_SMOKE_GATE (#1976)';
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-capabilities-sessionsweb-sync: {$err}\n");
    }
    fwrite(STDERR, "check-capabilities-sessionsweb-sync: FAILED (regenerate capability docs; see #1976)\n");
    exit(1);
}

fwrite(STDOUT, "check-capabilities-sessionsweb-sync: OK\n");
exit(0);
