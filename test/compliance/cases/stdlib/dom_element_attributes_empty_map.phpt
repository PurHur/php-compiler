--TEST--
DOMElement::attributes on attribute-less element returns empty DOMNamedNodeMap (#17619)
--FILE--
<?php
declare(strict_types=1);

$doc = new DOMDocument();
$attrs = $doc->createElement('p')->attributes;
if (!($attrs instanceof DOMNamedNodeMap)) {
    echo 'fail: not DOMNamedNodeMap';
    exit(1);
}
echo 'length=' . $attrs->length . "\n";

$el = $doc->createElement('div');
$el->setAttribute('id', 'x');
echo 'after=' . $el->attributes->length . "\n";
?>
--EXPECT--
length=0
after=1
