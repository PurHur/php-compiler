<?php
declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><child id="1"><inner/></child></root>');
$child = $doc->documentElement->firstChild;
$clone = $child->cloneNode(true);
if (!$clone instanceof DOMElement) {
    echo "fail: not element\n";
    exit(1);
}
$inner = $clone->firstChild;
if (!$inner instanceof DOMElement || 'inner' !== $inner->nodeName) {
    echo "fail: deep clone missing inner\n";
    exit(1);
}
$shallow = $child->cloneNode(false);
if (null !== $shallow->firstChild) {
    echo "fail: shallow clone has children\n";
    exit(1);
}
echo "ok\n";
