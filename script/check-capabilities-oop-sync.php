#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard generated capability docs for MiniWebApp OOP rows (issue #2190).
 *
 * Usage:
 *   php script/check-capabilities-oop-sync.php
 */

$root = dirname(__DIR__);
$syntax = $root.'/docs/capabilities-syntax.md';

$errors = [];

if (!is_readable($syntax)) {
    fwrite(STDERR, "check-capabilities-oop-sync: missing {$syntax}\n");
    exit(1);
}

$syntaxBody = (string) file_get_contents($syntax);

if (!str_contains($syntaxBody, '## OOP reference (`examples/003-MiniWebApp`)')) {
    $errors[] = 'docs/capabilities-syntax.md: missing 003-MiniWebApp OOP section (run: php script/capability-syntax.php)';
}

if (!preg_match('/\|\s*Instance methods \(`ClassMethod` \/ `Expr_MethodCall`\)\s*\|/i', $syntaxBody)) {
    $errors[] = 'docs/capabilities-syntax.md: missing Instance methods (`ClassMethod` / `Expr_MethodCall`) row';
}

if (!preg_match('/\|\s*Constructors \(`__construct`\)\s*\|/i', $syntaxBody)) {
    $errors[] = 'docs/capabilities-syntax.md: missing Constructors (`__construct`) row';
}

if (!preg_match('/\|\s*Private methods\s*\|[^\\n]*#145/i', $syntaxBody)) {
    $errors[] = 'docs/capabilities-syntax.md: Private methods row must link #145';
}

if (!preg_match('/\|\s*Method return types \(`: string` \/ `: void`\)\s*\|/i', $syntaxBody)) {
    $errors[] = 'docs/capabilities-syntax.md: missing method return types row (#55)';
}

if (!preg_match('/\|\s*`003-MiniWebApp` Router OOP \(VM serve\)\s*\|/i', $syntaxBody)) {
    $errors[] = 'docs/capabilities-syntax.md: missing 003-MiniWebApp Router OOP reference row';
}

if (!preg_match(
    '/\|\s*Public instance methods \(`Expr_MethodCall`\)\s*\|\s*yes\s*\|\s*partial\s*\|\s*yes\s*\|/i',
    $syntaxBody
)) {
    $errors[] = 'docs/capabilities-syntax.md: Public instance methods row must show VM yes, JIT partial, AOT yes (#58)';
}

if (!preg_match(
    '/\|\s*Private methods \+ `__construct`\s*\|\s*yes\s*\|\s*partial\s*\|\s*yes\s*\|/i',
    $syntaxBody
)) {
    $errors[] = 'docs/capabilities-syntax.md: Private methods + __construct row must show VM yes, JIT partial, AOT yes (#145)';
}

if (!preg_match(
    '/\|\s*Method return types \(`: string` \/ `: void`\)\s*\|\s*yes\s*\|\s*no\s*\|\s*partial\s*\|/i',
    $syntaxBody
)) {
    $errors[] = 'docs/capabilities-syntax.md: method return types curated row must show VM yes, JIT no, AOT partial (#55)';
}

if (!str_contains($syntaxBody, 'MINIWEBAPP_VM_OOP_GATE')
    || !str_contains($syntaxBody, 'MINIWEBAPP_JIT_PROJECT_GATE')
    || !str_contains($syntaxBody, 'CAPABILITIES_OOP_SYNC_GATE')) {
    $errors[] = 'docs/capabilities-syntax.md: OOP footer must mention MINIWEBAPP_VM_OOP_GATE, MINIWEBAPP_JIT_PROJECT_GATE, CAPABILITIES_OOP_SYNC_GATE (#2190)';
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-capabilities-oop-sync: {$err}\n");
    }
    fwrite(STDERR, "check-capabilities-oop-sync: FAILED (regenerate capability docs; see #2190)\n");
    exit(1);
}

fwrite(STDOUT, "check-capabilities-oop-sync: OK\n");
exit(0);
