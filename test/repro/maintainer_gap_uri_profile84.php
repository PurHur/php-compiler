<?php

declare(strict_types=1);

/**
 * PROFILE=8.4 must not advertise ext/uri (Zend 8.5-only; #26254).
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_uri_profile84.php
 */

if (extension_loaded('uri')) {
    echo "FAIL: extension_loaded('uri') true on PROFILE=8.4\n";
    exit(1);
}
if (class_exists('Uri\\Rfc3986\\Uri')) {
    echo "FAIL: Uri\\Rfc3986\\Uri available on PROFILE=8.4\n";
    exit(1);
}
if (class_exists('Uri\\WhatWg\\Url')) {
    echo "FAIL: Uri\\WhatWg\\Url available on PROFILE=8.4\n";
    exit(1);
}

echo "ok\n";
