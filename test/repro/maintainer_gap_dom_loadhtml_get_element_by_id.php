<?php

declare(strict_types=1);

/**
 * Issue #15251 — DOMDocument::getElementById() after loadHTML() indexes HTML id attributes.
 */

$doc = new DOMDocument();
$doc->loadHTML('<p id="target">hello</p>');
$found = $doc->getElementById('target');
if (null === $found) {
    echo "fail: getElementById returned null\n";
    exit(1);
}
if ('hello' !== $found->textContent) {
    echo 'fail: textContent=', $found->textContent, "\n";
    exit(1);
}
if (null !== $doc->getElementById('missing')) {
    echo "fail: missing id should be null\n";
    exit(1);
}

echo "ok\n";
