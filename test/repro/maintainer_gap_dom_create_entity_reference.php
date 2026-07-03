<?php

declare(strict_types=1);

$doc = new DOMDocument();
$ref = $doc->createEntityReference('amp');
if (!$ref instanceof DOMEntityReference) {
    echo 'fail: not DOMEntityReference ', get_class($ref), "\n";
    exit(1);
}
if (5 !== $ref->nodeType) {
    echo 'fail: nodeType ', $ref->nodeType, "\n";
    exit(1);
}
if ('amp' !== $ref->nodeName) {
    echo 'fail: nodeName ', $ref->nodeName, "\n";
    exit(1);
}

$root = $doc->createElement('root');
$doc->appendChild($root);
$root->appendChild($ref);
if (5 !== $root->firstChild->nodeType || 'amp' !== $root->firstChild->nodeName) {
    echo 'fail: appendChild child ', $root->firstChild->nodeName, ' type=', $root->firstChild->nodeType, "\n";
    exit(1);
}

try {
    $doc->createEntityReference('');
    echo "fail: empty name should throw\n";
    exit(1);
} catch (Throwable $e) {
    if ('Invalid Character Error' !== $e->getMessage()) {
        echo 'fail: empty name message ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
