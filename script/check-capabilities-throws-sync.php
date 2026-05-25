#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard generated capability docs for throw/try/catch + 007-ThrowsWeb rows (issue #2144, #2103).
 *
 * Usage:
 *   php script/check-capabilities-throws-sync.php
 */

$root = dirname(__DIR__);
$syntax = $root.'/docs/capabilities-syntax.md';

$errors = [];

if (!is_readable($syntax)) {
    fwrite(STDERR, "check-capabilities-throws-sync: missing {$syntax}\n");
    exit(1);
}

$syntaxBody = (string) file_get_contents($syntax);

if (!preg_match('/\|\s*`try` \/ `catch` \/ `throw`\s*\|/i', $syntaxBody)) {
    $errors[] = 'docs/capabilities-syntax.md: missing `try` / `catch` / `throw` construct row (run: php script/capability-syntax.php)';
}

if (!preg_match('/\|\s*`try` \/ `catch` \/ `throw`\s*\|[^\\n]*#57/i', $syntaxBody)) {
    $errors[] = 'docs/capabilities-syntax.md: `try` / `catch` / `throw` row must link #57';
}

if (!preg_match('/\|\s*`try` \/ `catch` \/ `throw`\s*\|[^\\n]*#195/i', $syntaxBody)) {
    $errors[] = 'docs/capabilities-syntax.md: `try` / `catch` / `throw` row must mention #195 throw lowering';
}

if (!preg_match('/\|\s*`try` \/ `catch` \/ `throw`\s*\|[^\\n]*#2084/i', $syntaxBody)) {
    $errors[] = 'docs/capabilities-syntax.md: `try` / `catch` / `throw` row must mention #2084 TryCatch overlay';
}

if (!str_contains($syntaxBody, '## Throws reference (`examples/007-ThrowsWeb`)')) {
    $errors[] = 'docs/capabilities-syntax.md: missing 007-ThrowsWeb section (run: php script/capability-syntax.php)';
}

if (!preg_match('/\|\s*`007-ThrowsWeb` reference app\s*\|/i', $syntaxBody)) {
    $errors[] = 'docs/capabilities-syntax.md: missing 007-ThrowsWeb reference row';
}

if (!preg_match(
    '/\|\s*`007-ThrowsWeb` reference app\s*\|\s*yes\s*\|\s*yes\s*\|\s*partial\s*\|/i',
    $syntaxBody
)) {
    $errors[] = 'docs/capabilities-syntax.md: 007-ThrowsWeb reference app must show VM yes, JIT yes, AOT partial (#2103)';
}

if (!preg_match(
    '/\|\s*`throw` \/ `catch` on invalid POST \(web serve\)\s*\|\s*yes\s*\|\s*yes\s*\|\s*partial\s*\|/i',
    $syntaxBody
)) {
    $errors[] = 'docs/capabilities-syntax.md: missing caught throw POST row with honest AOT partial (#2103)';
}

if (!str_contains($syntaxBody, 'THROWS_WEB_SMOKE_GATE')
    || !str_contains($syntaxBody, 'THROWSWEB_AOT_LINK_GATE')
    || !str_contains($syntaxBody, 'THROWSWEB_AOT_SMOKE_GATE')) {
    $errors[] = 'docs/capabilities-syntax.md: footer must mention THROWS_WEB_SMOKE_GATE, THROWSWEB_AOT_LINK_GATE, THROWSWEB_AOT_SMOKE_GATE (#2144)';
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-capabilities-throws-sync: {$err}\n");
    }
    fwrite(STDERR, "check-capabilities-throws-sync: FAILED (regenerate capability docs; see #2144)\n");
    exit(1);
}

fwrite(STDOUT, "check-capabilities-throws-sync: OK\n");
exit(0);
