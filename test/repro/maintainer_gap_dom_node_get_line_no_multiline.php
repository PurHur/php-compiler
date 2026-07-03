<?php

declare(strict_types=1);

$xml = "<root>\n  <child/>\n</root>";
$doc = new DOMDocument();
$doc->loadXML($xml);
$child = $doc->documentElement->firstChild;
if (!$child instanceof DOMElement) {
    foreach ($doc->documentElement->childNodes as $node) {
        if ($node instanceof DOMElement) {
            $child = $node;
            break;
        }
    }
}
if (!$child instanceof DOMElement) {
    fwrite(STDERR, "fail: child element not found\n");
    exit(1);
}
$line = $child->getLineNo();
if (2 !== $line) {
    fwrite(STDERR, "fail: getLineNo expected 2, got {$line}\n");
    exit(1);
}

echo "ok line={$line}\n";
