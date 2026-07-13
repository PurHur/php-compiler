<?php

declare(strict_types=1);

// Issue #18650 — DOMXPath::quote() PHP 8.4 XPath literal escaping (ext/dom/xpath.c).
if (!method_exists('DOMXPath', 'quote')) {
    echo "skip\n";
    exit(0);
}

$quoted = DOMXPath::quote("'quoted' name");
if ("\"'quoted' name\"" !== $quoted) {
    fwrite(STDERR, "fail: quote wrap got {$quoted}\n");
    exit(1);
}

$mixed = DOMXPath::quote("'different' \"quote\" styles");
$expectedMixed = <<<'X'
concat("'different' ",'"quote" styles')
X;
if ($expectedMixed !== $mixed) {
    fwrite(STDERR, "fail: mixed quotes got {$mixed}\n");
    exit(1);
}

try {
    DOMXPath::quote("tes\x00t");
    fwrite(STDERR, "fail: null byte should throw\n");
    exit(1);
} catch (ValueError $e) {
    if (!str_contains($e->getMessage(), 'must not contain any null bytes')) {
        fwrite(STDERR, 'fail: wrong null-byte message: '.$e->getMessage()."\n");
        exit(1);
    }
}

echo "ok\n";
