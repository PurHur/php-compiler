#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard Bootstrap SDK platform contract doc + JSON (#15606).
 *
 * Usage:
 *   php script/check-bootstrap-sdk-platform.php
 */

require_once __DIR__.'/bootstrap-sdk-platform-lib.php';

$root = dirname(__DIR__);
$errors = bootstrap_sdk_platform_check($root);

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-bootstrap-sdk-platform: {$err}\n");
    }
    fwrite(STDERR, "check-bootstrap-sdk-platform: FAILED — update docs/bootstrap-sdk-platform.{md,json} (#15606).\n");
    exit(1);
}

fwrite(STDOUT, "check-bootstrap-sdk-platform: OK (Linux x86_64 + LLVM 9 contract)\n");
exit(0);
