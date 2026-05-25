#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard examples/README.md against stale MiniWebApp execute wording (issues #1822, #1531).
 *
 * Fails when known post-#764 phrases remain while native execute is default-on in CI.
 *
 * Usage:
 *   php script/check-examples-readme-sync.php
 */

$root = dirname(__DIR__);
$readme = $root.'/examples/README.md';
$truth = $root.'/examples/003-MiniWebApp/README.md';

if (!is_readable($readme)) {
    fwrite(STDERR, "check-examples-readme-sync: missing {$readme}\n");
    exit(1);
}

$body = (string) file_get_contents($readme);

/** Phrases that imply #764 execute is still open. */
$stale = [
    'empty stdout until [#764]',
    'empty stdout until #764',
    'execute still returns empty stdout',
    'native execute 📋',
    'execute blocked',
    'blocked #764',
    'until [#764](https://github.com/PurHur/php-compiler/issues/764)',
];

$errors = [];
foreach ($stale as $phrase) {
    if (str_contains($body, $phrase)) {
        $errors[] = "stale phrase in examples/README.md: {$phrase}";
    }
}

if (!preg_match('/native execute\s*✅/u', $body)) {
    $errors[] = 'examples/README.md: run matrix should mark 003 native execute ✅ (post-#764)';
}

if (is_readable($truth)) {
    $mini = (string) file_get_contents($truth);
    if (str_contains($mini, 'native execute ✅') && !str_contains($body, 'native execute ✅')) {
        $errors[] = 'examples/README.md: out of sync with 003-MiniWebApp/README.md (execute status)';
    }
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-examples-readme-sync: {$err}\n");
    }
    fwrite(STDERR, "check-examples-readme-sync: FAILED (fix examples/README.md; see #1531)\n");
    exit(1);
}

fwrite(STDOUT, "check-examples-readme-sync: OK\n");
exit(0);
