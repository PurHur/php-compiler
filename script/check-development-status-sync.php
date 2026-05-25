#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard docs/pages/development-status.md against examples/README.md drift (issues #2039, #2067).
 *
 * Usage:
 *   php script/check-development-status-sync.php
 *
 * Default-on in ci-fast (issue #2083). Opt-out: DEVELOPMENT_STATUS_SYNC_GATE=0 ./script/ci-fast.sh
 */

$root = dirname(__DIR__);
$status = $root.'/docs/pages/development-status.md';
$examplesReadme = $root.'/examples/README.md';

if (!is_readable($status)) {
    fwrite(STDERR, "check-development-status-sync: missing {$status}\n");
    exit(1);
}

$body = (string) file_get_contents($status);
$errors = [];

if (!str_contains($body, '006-FileUploadWeb')) {
    $errors[] = 'development-status.md: missing 006-FileUploadWeb row (sync examples/README.md; #2039)';
}

if (preg_match('/Shipped examples \(000–005\)/', $body)) {
    $errors[] = 'development-status.md: section title still says 000–005 (update to 000–006; #2039)';
}

if (!preg_match('/Shipped examples \(000–006\)/', $body)) {
    $errors[] = 'development-status.md: missing "Shipped examples (000–006)" section header (#2039)';
}

if (!preg_match('/\| 006-FileUploadWeb \|/', $body)) {
    $errors[] = 'development-status.md: shipped table missing | 006-FileUploadWeb | row (#2039)';
}

if (!str_contains($body, '#1493')) {
    $errors[] = 'development-status.md: missing M3 HelloWorld strict reference #1493';
}

if (!preg_match('/compile-smoke[^\n]{0,120}#1937/i', $body)) {
    $errors[] = 'development-status.md: missing compile-smoke 🚧 #1937 (NS2 M3 row)';
}

if (!str_contains($body, 'bootstrap-selfhost.md')) {
    $errors[] = 'development-status.md: missing link to docs/bootstrap-selfhost.md (NS2 gates)';
}

if (is_readable($examplesReadme)) {
    $examples = (string) file_get_contents($examplesReadme);
    if (preg_match('/\| \[006-FileUploadWeb\][^\n]*✅/u', $examples)
        && preg_match('/\| 006-FileUploadWeb \|[^\n]*🚧/u', $body)) {
        $errors[] = 'development-status.md: 006 row shows 🚧 but examples/README.md is ✅ (#2039)';
    }
    if (str_contains($examples, '006-FileUploadWeb') && !str_contains($body, 'FILE_UPLOAD_WEB_SMOKE_GATE')) {
        $errors[] = 'development-status.md: missing FILE_UPLOAD_WEB_SMOKE_GATE wording (sync #2009)';
    }
}

$smokeDefault = ci_defaults_gate_default($root, 'FILE_UPLOAD_WEB_SMOKE_GATE');
$linkDefault = ci_defaults_gate_default($root, 'FILE_UPLOAD_WEB_AOT_LINK_GATE');
$aotDefault = ci_defaults_gate_default($root, 'FILE_UPLOAD_WEB_AOT_SMOKE_GATE');

if ('1' === $smokeDefault && !preg_match('/FILE_UPLOAD_WEB_SMOKE_GATE=1/', $body)) {
    $errors[] = 'development-status.md: FILE_UPLOAD_WEB_SMOKE_GATE=1 expected (ci-defaults default-on #2009)';
}
if ('1' === $linkDefault && !preg_match('/FILE_UPLOAD_WEB_AOT_LINK_GATE=1/', $body)) {
    $errors[] = 'development-status.md: FILE_UPLOAD_WEB_AOT_LINK_GATE=1 expected (ci-defaults default-on #2011)';
}
if ('1' === $aotDefault && !preg_match('/FILE_UPLOAD_WEB_AOT_SMOKE_GATE=1/', $body)) {
    $errors[] = 'development-status.md: FILE_UPLOAD_WEB_AOT_SMOKE_GATE=1 expected (ci-defaults default-on #2012)';
}

$m3SmokeDefault = ci_defaults_gate_default($root, 'BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE');
if ('1' === $m3SmokeDefault && !str_contains($body, 'BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE')) {
    $errors[] = 'development-status.md: missing BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE (NS2 M3 ladder)';
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-development-status-sync: {$err}\n");
    }
    fwrite(STDERR, "check-development-status-sync: FAILED (fix docs/pages/development-status.md; #2039, #2067)\n");
    exit(1);
}

fwrite(STDOUT, "check-development-status-sync: OK\n");
exit(0);

function ci_defaults_gate_default(string $repoRoot, string $gate): string
{
    $path = $repoRoot.'/script/ci-defaults.env';
    if (!is_readable($path)) {
        return '1';
    }
    $envBody = (string) file_get_contents($path);
    $pattern = '/export\s+'.preg_quote($gate, '/').'="\$\{'
        .preg_quote($gate, '/').':-([01])\}"/';
    if (preg_match($pattern, $envBody, $m)) {
        return $m[1];
    }

    return '1';
}
