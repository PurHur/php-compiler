<?php

declare(strict_types=1);

/**
 * Issue #14468 — DOMDocument::$documentURI after loadXML matches Zend cwd default.
 */

$d = new DOMDocument();
if (null !== $d->documentURI) {
    fwrite(STDERR, "fail: fresh documentURI should be null\n");
    exit(1);
}

$d->loadXML('<a/>');
$uri = $d->documentURI;
if (!\is_string($uri) || '' === $uri) {
    fwrite(STDERR, 'fail: documentURI should be non-empty string after loadXML, got '.var_export($uri, true)."\n");
    exit(1);
}

$cwd = getcwd();
if (false === $cwd) {
    fwrite(STDERR, "skip: getcwd unavailable\n");
    exit(0);
}
$expected = str_ends_with($cwd, '/') ? $cwd : $cwd.'/';
if ($expected !== $uri) {
    fwrite(STDERR, "fail: documentURI expected {$expected}, got {$uri}\n");
    exit(1);
}

echo "ok\n";
