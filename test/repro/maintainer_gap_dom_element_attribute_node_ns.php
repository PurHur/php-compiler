<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<r xmlns:ns="urn:x"><e ns:a="1" id="z"/></r>');
$el = $doc->documentElement->firstChild;
if (!($el instanceof DOMElement)) {
    fwrite(STDERR, "fail: expected element\n");
    exit(1);
}

if (!method_exists($el, 'getAttributeNodeNS') || !method_exists($el, 'setAttributeNodeNS')) {
    fwrite(STDERR, "fail: AttributeNodeNS methods missing\n");
    exit(1);
}

$a = $el->getAttributeNodeNS('urn:x', 'a');
if (null === $a || !($a instanceof DOMAttr)) {
    fwrite(STDERR, "fail: getAttributeNodeNS should return DOMAttr\n");
    exit(1);
}
if ('ns:a' !== $a->nodeName || '1' !== $a->nodeValue) {
    fwrite(STDERR, "fail: unexpected attr {$a->nodeName}={$a->nodeValue}\n");
    exit(1);
}

$missing = $el->getAttributeNodeNS('urn:x', 'missing');
if (null !== $missing) {
    fwrite(STDERR, "fail: getAttributeNodeNS miss should be null\n");
    exit(1);
}

$new = $doc->createAttributeNS('urn:x', 'ns:b');
$new->value = '2';
$prev = $el->setAttributeNodeNS($new);
if (null !== $prev) {
    fwrite(STDERR, "fail: setAttributeNodeNS insert should return null\n");
    exit(1);
}
if ('2' !== $el->getAttributeNS('urn:x', 'b')) {
    fwrite(STDERR, "fail: setAttributeNodeNS did not store value\n");
    exit(1);
}

$repl = $doc->createAttributeNS('urn:x', 'ns:a');
$repl->value = '9';
$old = $el->setAttributeNodeNS($repl);
if (null === $old || 'ns:a' !== $old->nodeName || '1' !== $old->nodeValue) {
    fwrite(STDERR, "fail: setAttributeNodeNS replace should return previous attr\n");
    exit(1);
}
if ('9' !== $el->getAttributeNS('urn:x', 'a')) {
    fwrite(STDERR, "fail: replace did not update value\n");
    exit(1);
}

echo "ok\n";
