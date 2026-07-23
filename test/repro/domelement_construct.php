<?php
// Issue #22598 repro — method_exists + construct parity (VM; method_exists trips MCJIT pcreJit).
echo 'method_exists=', method_exists(DOMElement::class, '__construct') ? '1' : '0', "\n";
try {
    $e = new DOMElement('foo', 'bar');
    echo 'tag=', $e->tagName, ' text=', $e->textContent, "\n";
    echo 'owner=', ($e->ownerDocument === null ? 'null' : get_class($e->ownerDocument)), "\n";
} catch (Throwable $ex) {
    echo get_class($ex), ': ', $ex->getMessage(), "\n";
}
$e2 = new DOMElement('ns:x', null, 'http://example.com');
echo 'ns_tag=', $e2->tagName, ' ns=', (string) $e2->namespaceURI, ' local=', $e2->localName, "\n";
$e3 = new DOMElement('foo');
echo 'empty_text=[', $e3->textContent, '] children=', $e3->childNodes->length, "\n";
try {
    new DOMElement('a:b', 'v', '');
} catch (Throwable $ex) {
    echo 'prefix_empty_ns=', get_class($ex), "\n";
}
$doc = new DOMDocument();
$imported = $doc->importNode(new DOMElement('foo', 'bar'), true);
$doc->appendChild($imported);
echo 'after_import=', $doc->documentElement->tagName, ' text=', $doc->documentElement->textContent, "\n";
