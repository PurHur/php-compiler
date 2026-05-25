#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard generated capability docs for throw / try / catch rows (issues #2103, #2144).
 *
 * Usage:
 *   php script/check-capabilities-throws-sync.php
 */

$root = dirname(__DIR__);
$capabilities = $root.'/docs/capabilities.md';
$syntax = $root.'/docs/capabilities-syntax.md';

$errors = [];

if (!is_readable($capabilities)) {
    fwrite(STDERR, "check-capabilities-throws-sync: missing {$capabilities}\n");
    exit(1);
}
if (!is_readable($syntax)) {
    fwrite(STDERR, "check-capabilities-throws-sync: missing {$syntax}\n");
    exit(1);
}

$syntaxBody = (string) file_get_contents($syntax);

if (!preg_match(
    '/\|\s*`try`\s*\/\s*`catch`\s*\/\s*`throw`\s*\|\s*yes\s*\|\s*yes\s*\|\s*yes\s*\|/i',
    $syntaxBody
)) {
    $errors[] = 'docs/capabilities-syntax.md: `try` / `catch` / `throw` row must show VM/JIT/AOT yes (run: php script/capability-syntax.php)';
}

if (!str_contains($syntaxBody, 'issues/57') && !str_contains($syntaxBody, '#57')) {
    $errors[] = 'docs/capabilities-syntax.md: try/catch/throw row must link issue #57';
}

if (!str_contains($syntaxBody, 'issues/195') && !str_contains($syntaxBody, '#195')) {
    $errors[] = 'docs/capabilities-syntax.md: throw notes must reference issue #195';
}

if (!str_contains($syntaxBody, 'issues/2084') && !str_contains($syntaxBody, '#2084')) {
    $errors[] = 'docs/capabilities-syntax.md: try/catch notes must reference issue #2084';
}

if (!preg_match(
    '/\|\s*Multi-type catch `catch \(A\|B \$e\)`\s*\|\s*yes\s*\|\s*yes\s*\|\s*yes\s*\|/i',
    $syntaxBody
)) {
    $errors[] = 'docs/capabilities-syntax.md: multi-type catch row must show VM/JIT/AOT yes (#1362)';
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
    $errors[] = 'docs/capabilities-syntax.md: 007-ThrowsWeb reference app must show AOT partial until #2101';
}

if (!preg_match(
    '/\|\s*`try`\s*\/\s*`catch`\s*\/\s*`throw` \(web form validation\)\s*\|\s*yes\s*\|\s*yes\s*\|\s*partial\s*\|/i',
    $syntaxBody
)) {
    $errors[] = 'docs/capabilities-syntax.md: 007 web try/catch/throw construct row missing or wrong columns';
}

if (!str_contains($syntaxBody, 'THROWS_WEB_SMOKE_GATE')) {
    $errors[] = 'docs/capabilities-syntax.md: footer must mention THROWS_WEB_SMOKE_GATE (#2093)';
}

if (!str_contains($syntaxBody, 'THROWSWEB_AOT_LINK_GATE') || !str_contains($syntaxBody, 'THROWSWEB_AOT_SMOKE_GATE')) {
    $errors[] = 'docs/capabilities-syntax.md: footer must mention THROWSWEB_AOT_* gates (#2101)';
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-capabilities-throws-sync: {$err}\n");
    }
    fwrite(STDERR, "check-capabilities-throws-sync: FAILED (regenerate: php script/capability-syntax.php; see #2144)\n");
    exit(1);
}

fwrite(STDOUT, "check-capabilities-throws-sync: OK\n");
exit(0);
