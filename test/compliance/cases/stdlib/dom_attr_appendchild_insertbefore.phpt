--TEST--
stdlib DOMElement appendChild/insertBefore accepts DOMAttr (#19445, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$el = $doc->createElement('el');
$doc->appendChild($el);

$a = $doc->createAttribute('foo');
$a->value = 'bar';
$ret = $el->appendChild($a);
echo get_class($ret), ':', $el->getAttribute('foo'), ':', $el->hasAttribute('foo') ? 'Y' : 'N', ':', $el->childNodes->length, "\n";
echo $doc->saveXML($el), "\n";

$b = $doc->createAttribute('baz');
$b->value = 'qux';
$ret2 = $el->insertBefore($b, null);
echo get_class($ret2), ':', $el->getAttribute('baz'), ':', $el->childNodes->length, "\n";

$c = $doc->createAttribute('foo');
$c->value = 'new';
$ret3 = $el->appendChild($c);
echo $ret3->value, ':', $el->getAttribute('foo'), "\n";

$child = $doc->createElement('c');
$el->appendChild($child);
$d = $doc->createAttribute('x');
$d->value = '1';
try {
    $el->insertBefore($d, $child);
    echo "insert-ref:ok\n";
} catch (DOMException $ex) {
    echo "insert-ref:DOMException\n";
} catch (Throwable $ex) {
    echo 'insert-ref:', get_class($ex), "\n";
}

$e2 = $doc->createElement('el2');
$el->appendChild($e2);
$m = $doc->createAttribute('moved');
$m->value = 'v';
$el->appendChild($m);
$e2->appendChild($m);
echo 'move:', $el->hasAttribute('moved') ? 'Y' : 'N', ':', $e2->getAttribute('moved'), "\n";

try {
    $doc->appendChild($doc->createAttribute('doc'));
    echo "doc:ok\n";
} catch (DOMException $ex) {
    echo "doc:DOMException\n";
}

try {
    $el->appendChild('not-a-node');
    echo "typenon:ok\n";
} catch (TypeError $ex) {
    echo "typenon:TypeError\n";
}
?>
--EXPECT--
DOMAttr:bar:Y:0
<el foo="bar"/>
DOMAttr:qux:0
new:new
insert-ref:Error
move:N:v
doc:DOMException
typenon:TypeError
