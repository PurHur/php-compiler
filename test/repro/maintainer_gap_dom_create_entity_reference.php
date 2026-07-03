<?php

declare(strict_types=1);

$doc = new DOMDocument();
$ref = $doc->createEntityReference('amp');
if (false === $ref) {
    echo "fail: createEntityReference returned false\n";
    exit(1);
}
if (!($ref instanceof DOMEntityReference)) {
    echo 'fail: expected DOMEntityReference, got ', get_debug_type($ref), "\n";
    exit(1);
}
if (5 !== $ref->nodeType) {
    echo 'fail: nodeType=', $ref->nodeType, "\n";
    exit(1);
}
if ('amp' !== $ref->nodeName) {
    echo 'fail: nodeName=', $ref->nodeName, "\n";
    exit(1);
}
if (null !== $ref->nodeValue) {
    echo 'fail: nodeValue=', var_export($ref->nodeValue, true), "\n";
    exit(1);
}
if ($ref->ownerDocument !== $doc) {
    echo "fail: ownerDocument mismatch\n";
    exit(1);
}

echo "ok\n";
