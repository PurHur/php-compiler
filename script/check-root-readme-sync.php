#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard root README.md against stale MiniWebApp north-star wording (issue #1832).
 *
 * Fails when known post-#764 blocker phrases remain while examples/README.md
 * documents native execute as green. Enable in CI via ROOT_README_SYNC_GATE=1
 * (default in ci-defaults.env after #1525). Opt out: ROOT_README_SYNC_GATE=0.
 *
 * Usage:
 *   php script/check-root-readme-sync.php
 */

$root = dirname(__DIR__);
$readme = $root.'/README.md';
$examplesReadme = $root.'/examples/README.md';

if (!is_readable($readme)) {
    fwrite(STDERR, "check-root-readme-sync: missing {$readme}\n");
    exit(1);
}

$body = (string) file_get_contents($readme);
$lines = preg_split("/\r\n|\n|\r/", $body) ?: [];

/** Phrases that imply #764 native execute is still open (issue #1832). */
$stale = [
    'empty stdout until [#764]',
    'empty stdout until #764',
    'native execute blocked',
    'execute blocked #764',
    'blocked #764',
    'native execute partial',
    'execute 🚧',
];

$errors = [];
foreach ($stale as $phrase) {
    if (!str_contains($body, $phrase)) {
        continue;
    }
    foreach ($lines as $num => $line) {
        if (!str_contains($line, $phrase)) {
            continue;
        }
        $lineNo = $num + 1;
        $errors[] = "stale phrase in README.md:{$lineNo}: {$phrase}";
    }
}

if (preg_match('/003[^\n]{0,80}AOT execute[^\n]*🚧/u', $body)
    || preg_match('/\*\*003\*\*[^\n]{0,40}execute[^\n]*🚧/u', $body)) {
    $errors[] = 'README.md: 003 AOT execute should not be 🚧 partial (post-#764; sync #1525)';
}

if (is_readable($examplesReadme)) {
    $examples = (string) file_get_contents($examplesReadme);
    if (str_contains($examples, 'native execute ✅') && !str_contains($body, 'native execute ✅')) {
        $errors[] = 'README.md: out of sync with examples/README.md (003 native execute status)';
    }
    if (str_contains($examples, '005-SessionsWeb') && !str_contains($body, '005-SessionsWeb')) {
        $errors[] = 'README.md: missing 005-SessionsWeb row (sync examples/README.md; #1924)';
    }
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-root-readme-sync: {$err}\n");
    }
    fwrite(STDERR, "check-root-readme-sync: FAILED (fix README.md; see #48, #1525, #1832)\n");
    exit(1);
}

fwrite(STDOUT, "check-root-readme-sync: OK\n");
exit(0);
