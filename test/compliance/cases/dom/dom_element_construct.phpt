--TEST--
DOMElement::__construct orphaned element with value/namespace (#22598)
--FILE--
<?php
$e = new DOMElement('foo', 'bar');
echo 'tag=', $e->tagName, ' text=', $e->textContent, "\n";
echo 'owner=', ($e->ownerDocument === null ? 'null' : get_class($e->ownerDocument)), "\n";
$e2 = new DOMElement('ns:x', null, 'http://example.com');
echo 'ns_tag=', $e2->tagName, ' ns=', (string) $e2->namespaceURI, ' local=', $e2->localName, "\n";
$e3 = new DOMElement('foo');
echo 'empty_text=[', $e3->textContent, '] children=', $e3->childNodes->length, "\n";
try {
    new DOMElement('a:b', 'v', '');
    echo "prefix_empty_ns=ok\n";
} catch (DOMException $ex) {
    echo 'prefix_empty_ns=DOMException code=', $ex->getCode(), "\n";
}
$doc = new DOMDocument();
$imported = $doc->importNode(new DOMElement('foo', 'bar'), true);
$doc->appendChild($imported);
echo 'after_import=', $doc->documentElement->tagName, ' text=', $doc->documentElement->textContent, "\n";
try {
    new DOMElement();
} catch (ArgumentCountError $ex) {
    echo "argc=ArgumentCountError\n";
}
try {
    new DOMElement('');
} catch (DOMException $ex) {
    echo 'empty_name=DOMException code=', $ex->getCode(), "\n";
}
--EXPECT--
tag=foo text=bar
owner=null
ns_tag=ns:x ns=http://example.com local=x
empty_text=[] children=0
prefix_empty_ns=DOMException code=14
after_import=foo text=bar
argc=ArgumentCountError
empty_name=DOMException code=5
