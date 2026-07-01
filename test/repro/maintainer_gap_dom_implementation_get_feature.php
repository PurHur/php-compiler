<?php

declare(strict_types=1);

/**
 * Issue #14494 — DOMImplementation::getFeature() registered (ext/dom/implementation.c).
 */

$impl = new DOMImplementation();
if (!method_exists($impl, 'getFeature')) {
    fwrite(STDERR, "fail: DOMImplementation::getFeature() undefined\n");
    exit(1);
}

try {
    $impl->getFeature('Core', '2.0');
    fwrite(STDERR, "fail: getFeature should throw\n");
    exit(1);
} catch (Error $e) {
    if ('Not yet implemented' !== $e->getMessage()) {
        fwrite(STDERR, "fail: unexpected error: {$e->getMessage()}\n");
        exit(1);
    }
}

echo "ok\n";
