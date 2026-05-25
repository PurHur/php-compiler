#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard generated capability docs for 006-FileUploadWeb + multipart AOT columns (issue #2019).
 *
 * Usage:
 *   php script/check-capabilities-fileuploadweb-sync.php
 */

$root = dirname(__DIR__);
$capabilities = $root.'/docs/capabilities.md';
$syntax = $root.'/docs/capabilities-syntax.md';

$errors = [];

if (!is_readable($capabilities)) {
    fwrite(STDERR, "check-capabilities-fileuploadweb-sync: missing {$capabilities}\n");
    exit(1);
}
if (!is_readable($syntax)) {
    fwrite(STDERR, "check-capabilities-fileuploadweb-sync: missing {$syntax}\n");
    exit(1);
}

$capBody = (string) file_get_contents($capabilities);
$syntaxBody = (string) file_get_contents($syntax);

if (!str_contains($syntaxBody, '## File upload reference (`examples/006-FileUploadWeb`)')) {
    $errors[] = 'docs/capabilities-syntax.md: missing 006-FileUploadWeb section (run: php script/capability-syntax.php)';
}

if (!preg_match('/\|\s*`006-FileUploadWeb` reference app\s*\|/i', $syntaxBody)) {
    $errors[] = 'docs/capabilities-syntax.md: missing 006-FileUploadWeb reference row';
}

if (!preg_match(
    '/\|\s*`move_uploaded_file`\s*\|\s*yes\s*\|\s*yes\s*\|\s*yes\s*\|/i',
    $capBody
)) {
    $errors[] = 'docs/capabilities.md: `move_uploaded_file` must show VM yes, JIT yes, AOT yes (#2005)';
}

if (!str_contains($capBody, '006-FileUploadWeb') || !str_contains($capBody, 'FILE_UPLOAD_WEB_AOT_SMOKE_GATE')) {
    $errors[] = 'docs/capabilities.md: `move_uploaded_file` notes must mention 006 and FILE_UPLOAD_WEB_AOT_SMOKE_GATE (#2019)';
}

if (!str_contains($capBody, 'FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE')) {
    $errors[] = 'docs/capabilities.md: `move_uploaded_file` notes must mention FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE (#2045, #2028)';
}

if (!str_contains($capBody, '$_FILES') && !str_contains($capBody, 'nested $_FILES')) {
    $errors[] = 'docs/capabilities.md: move_uploaded_file or notes must reference $_FILES web runtime (#2019)';
}

if (!preg_match(
    '/\|\s*`006-FileUploadWeb` reference app\s*\|\s*yes\s*\|\s*yes\s*\|\s*yes\s*\|/i',
    $syntaxBody
)) {
    $errors[] = 'docs/capabilities-syntax.md: 006-FileUploadWeb reference app must show AOT yes (#2019)';
}

if (!preg_match(
    '/\|\s*multipart `\\$_POST` \\/ nested `\\$_FILES` \(web runtime\)\s*\|\s*yes\s*\|\s*yes\s*\|\s*yes\s*\|/i',
    $syntaxBody
)) {
    $errors[] = 'docs/capabilities-syntax.md: nested $_FILES construct must show AOT yes (#2019)';
}

if (!str_contains($syntaxBody, 'FILE_UPLOAD_WEB_AOT_SMOKE_GATE')) {
    $errors[] = 'docs/capabilities-syntax.md: footer must mention FILE_UPLOAD_WEB_AOT_SMOKE_GATE (#2019)';
}

if (!str_contains($syntaxBody, 'FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE')) {
    $errors[] = 'docs/capabilities-syntax.md: footer must mention FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE (#2045, #2028)';
}

if (!preg_match(
    '/\|\s*AOT deploy CGI \(multipart upload smoke\)\s*\|\s*n\/a\s*\|\s*n\/a\s*\|\s*yes\s*\|/i',
    $syntaxBody
)) {
    $errors[] = 'docs/capabilities-syntax.md: missing AOT deploy CGI row for 006 (#2045, #2028)';
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-capabilities-fileuploadweb-sync: {$err}\n");
    }
    fwrite(STDERR, "check-capabilities-fileuploadweb-sync: FAILED (regenerate capability docs; see #2019)\n");
    exit(1);
}

fwrite(STDOUT, "check-capabilities-fileuploadweb-sync: OK\n");
