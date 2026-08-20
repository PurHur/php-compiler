--TEST--
AOT: DOMElement::insertAdjacentElement/Text — Zend php_dom.c / element.c
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$el = $doc->createElement('div');
$root->appendChild($el);
$inner = $doc->createElement('b');
$returned = $el->insertAdjacentElement('afterbegin', $inner);
echo ($returned === $inner ? 'same' : 'diff'), "\n";
echo $el->firstChild->nodeName, "\n";
$el->insertAdjacentText('beforeend', 'x');
echo $el->firstChild->nodeName, "\n";
echo null === $el->insertAdjacentElement('beforeend', null) ? "null\n" : "notnull\n";
try {
    $el->insertAdjacentElement('nope', $inner);
    echo "bad\n";
} catch (ValueError $e) {
    echo "ValueError\n";
}
--EXPECT--
same
b
b
null
ValueError
