#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard docs/pages/development-status.md 009-FastCGIWeb row (issue #2353).
 *
 * Fails when the public status page drops 009 while examples/README.md documents it,
 * or when VM / FastCGI columns drift from the example README SSOT.
 *
 * Enable in CI via DEVELOPMENT_STATUS_009_SYNC_GATE=1 (default in ci-defaults.env after #2353).
 * Opt out: DEVELOPMENT_STATUS_009_SYNC_GATE=0 ./script/ci-fast.sh
 *
 * Usage:
 *   php script/check-development-status-009-sync.php
 */

$root = dirname(__DIR__);
$status = $root.'/docs/pages/development-status.md';
$examplesReadme = $root.'/examples/README.md';
$example009 = $root.'/examples/009-FastCGIWeb/example.php';

if (!is_file($example009)) {
    fwrite(STDOUT, "check-development-status-009-sync: OK (009-FastCGIWeb tree absent)\n");
    exit(0);
}

if (!is_readable($status)) {
    fwrite(STDERR, "check-development-status-009-sync: missing {$status}\n");
    exit(1);
}

$body = (string) file_get_contents($status);
$errors = [];

if (preg_match('/Shipped examples \(000–00[0-7]\)/', $body) && !preg_match('/Shipped examples \(000–009\)/', $body)) {
    $errors[] = 'development-status.md: section title stale (update to 000–009; #2353)';
}

if (!preg_match('/Shipped examples \(000–009\)/', $body)) {
    $errors[] = 'development-status.md: missing "Shipped examples (000–009)" section header (#2353)';
}

if (!str_contains($body, '009-FastCGIWeb')) {
    $errors[] = 'development-status.md: missing 009-FastCGIWeb row (#2353)';
}

if (!preg_match('/\| 009-FastCGIWeb \|/', $body)) {
    $errors[] = 'development-status.md: shipped table missing | 009-FastCGIWeb | row (#2353)';
}

if (!str_contains($body, '#2331')) {
    $errors[] = 'development-status.md: 009 row must link #2331 (#2353)';
}

if (!preg_match('/#173/', $body)) {
    $errors[] = 'development-status.md: 009 row must reference FastCGI adapter #173 (#2353)';
}

if (!str_contains($body, 'FASTCGI_WEB_SMOKE_GATE')) {
    $errors[] = 'development-status.md: missing FASTCGI_WEB_SMOKE_GATE wording (#2351, #2353)';
}

if (is_readable($examplesReadme)) {
    $examples = (string) file_get_contents($examplesReadme);
    if (preg_match('/\| \[009-FastCGIWeb\][^\n]*✅/u', $examples)
        && preg_match('/\| 009-FastCGIWeb \|[^\n]*🚧/u', $body)
        && !preg_match('/\| 009-FastCGIWeb \|[^\n]*✅/u', $body)) {
        $errors[] = 'development-status.md: 009 VM column should be ✅ when examples/README.md VM is ✅ (#2353)';
    }
    if (preg_match('/\| \[009-FastCGIWeb\][^\n]*📋[^\n]*#173/u', $examples)
        && !preg_match('/\| 009-FastCGIWeb \|[^\n]*📋/u', $body)
        && !preg_match('/009-FastCGIWeb[^\n]*📋[^\n]*#173/u', $body)) {
        $errors[] = 'development-status.md: 009 FastCGI execute should be 📋 #173 when examples/README.md is (#2353)';
    }
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-development-status-009-sync: {$err}\n");
    }
    fwrite(STDERR, "check-development-status-009-sync: FAILED (fix docs/pages/development-status.md; #2353)\n");
    exit(1);
}

fwrite(STDOUT, "check-development-status-009-sync: OK\n");
exit(0);
