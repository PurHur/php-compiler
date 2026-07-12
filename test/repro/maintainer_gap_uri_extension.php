<?php

declare(strict_types=1);

if (extension_loaded('uri')) {
    echo "FAIL: extension_loaded('uri') true on reference profile\n";
    exit(1);
}
if (class_exists(\Uri\Rfc3986\Uri::class)) {
    echo "FAIL: Uri\\Rfc3986\\Uri available on reference profile\n";
    exit(1);
}
if (class_exists(\Uri\WhatWg\Url::class)) {
    echo "FAIL: Uri\\WhatWg\\Url available on reference profile\n";
    exit(1);
}

echo "ok\n";
