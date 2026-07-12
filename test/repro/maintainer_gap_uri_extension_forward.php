<?php

declare(strict_types=1);

var_export(extension_loaded('uri'));
echo "\n";
var_export(class_exists(\Uri\Rfc3986\Uri::class));
echo "\n";

$u = \Uri\Rfc3986\Uri::parse('https://example.com/path?q=1');
var_export($u?->getHost());
echo "\n";
var_export($u?->getPath());
echo "\n";

$w = \Uri\WhatWg\Url::parse('https://example.org/foo');
var_export($w?->getAsciiHost());
echo "\n";

$invalid = \Uri\Rfc3986\Uri::parse('://bad');
if (null !== $invalid) {
    echo "fail: invalid URI must parse as null\n";
    exit(1);
}

echo "ok\n";
