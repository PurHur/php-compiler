<?php
declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><child id="target">x</child></root>');
$child = $doc->getElementsByTagName('child')->item(0);
if (null === $child) {
    fwrite(STDERR, "FAIL: child element missing\n");
    exit(1);
}
$foundBefore = $doc->getElementById('target');
if (null !== $foundBefore) {
    fwrite(STDERR, "FAIL: getElementById before setIdAttribute should be null\n");
    exit(1);
}
$child->setIdAttribute('id', true);
$found = $doc->getElementById('target');
if (null === $found) {
    fwrite(STDERR, "FAIL: getElementById after setIdAttribute returned null\n");
    exit(1);
}
echo "OK\n";
