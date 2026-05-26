#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard root README.md 009-FastCGIWeb shipped-example row (issue #2353).
 *
 * Fails when examples/README.md documents 009 but README.md drops the row,
 * omits FastCGI / #173 tracker links, or documents harness-unsafe docker bind mounts.
 *
 * Enable in CI via ROOT_README_009_SYNC_GATE=1 (default in ci-defaults.env after #2353).
 * Opt out: ROOT_README_009_SYNC_GATE=0 ./script/ci-fast.sh
 *
 * Usage:
 *   php script/check-root-readme-009-sync.php
 */

$root = dirname(__DIR__);
$readme = $root.'/README.md';
$examplesReadme = $root.'/examples/README.md';
$example009 = $root.'/examples/009-FastCGIWeb/example.php';

if (!is_file($example009)) {
    fwrite(STDOUT, "check-root-readme-009-sync: OK (009-FastCGIWeb tree absent)\n");
    exit(0);
}

if (!is_readable($readme)) {
    fwrite(STDERR, "check-root-readme-009-sync: missing {$readme}\n");
    exit(1);
}

$body = (string) file_get_contents($readme);
$errors = [];

if (is_readable($examplesReadme)) {
    $examples = (string) file_get_contents($examplesReadme);
    if (str_contains($examples, '009-FastCGIWeb') && !str_contains($body, '009-FastCGIWeb')) {
        $errors[] = 'README.md: missing 009-FastCGIWeb row (sync examples/README.md; #2353)';
    }
}

if (!preg_match('/\| \[009-FastCGIWeb\]/', $body)) {
    $errors[] = 'README.md: shipped examples table missing [009-FastCGIWeb] row (#2353)';
}

if (!preg_match('/009-FastCGIWeb\][^\n]*#173/u', $body) && !preg_match('/FastCGI[^\n]*#173/u', $body)) {
    $errors[] = 'README.md: 009 row must reference FastCGI adapter #173 (#2353)';
}

if (!str_contains($body, '#2331')) {
    $errors[] = 'README.md: 009 row must link #2331 (example fixture SSOT; #2353)';
}

if (!preg_match('/examples\/README\.md/i', $body)) {
    $errors[] = 'README.md: missing link to examples/README.md (009 ladder; #2353)';
}

$lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
foreach ($lines as $num => $line) {
    if (!preg_match('/docker\s+run[^\n]*-v\s*["\']?\$\(pwd\)/i', $line)
        && !preg_match('/docker\s+run[^\n]*-v\s*["\']?\$\{PWD\}/i', $line)) {
        continue;
    }
    if (preg_match('/never|do not|may show|Symptoms|harness hosts/i', $line)) {
        continue;
    }
    if (!preg_match('/009|FastCGI|FASTCGI/i', $line)) {
        continue;
    }
    $lineNo = $num + 1;
    $errors[] = "README.md:{$lineNo}: 009 docs must use ./script/docker-exec.sh, not raw docker run -v \"\$(pwd)\" (#2353)";
}

if (preg_match('/\| \[009-FastCGIWeb\][^\n]*🚧/u', $body)
    && is_readable($examplesReadme)
    && preg_match('/\| \[009-FastCGIWeb\][^\n]*✅/u', (string) file_get_contents($examplesReadme))) {
    $errors[] = 'README.md: 009-FastCGIWeb VM column shows 🚧 but examples/README.md is ✅ (#2353)';
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-root-readme-009-sync: {$err}\n");
    }
    fwrite(STDERR, "check-root-readme-009-sync: FAILED (fix README.md; see #2353)\n");
    exit(1);
}

fwrite(STDOUT, "check-root-readme-009-sync: OK\n");
exit(0);
