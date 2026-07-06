--TEST--
stdlib DOMElement::insertAdjacentText() — PHP 8.4 profile (#16914, ext/dom/element.c)
--FILE--
<?php
$dom = new DOMDocument();
$dom->loadXML('<?xml version="1.0"?><container><p>H</p></container>');
$p = $dom->getElementsByTagName('p')->item(0);
$p->insertAdjacentText('afterbegin', 'P');
$p->insertAdjacentText('beforeend', 'P');
echo $dom->saveXML();
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$el = $doc->createElement('div');
$root->appendChild($el);
$el->insertAdjacentText('beforebegin', 'A');
$el->insertAdjacentText('afterbegin', 'B');
$el->insertAdjacentText('beforeend', 'C');
$el->insertAdjacentText('afterend', 'D');
echo preg_replace('/\s+/', '', $doc->saveHTML($root)), "\n";
try {
    $el->insertAdjacentText('nope', 'x');
    echo "bad\n";
} catch (ValueError $e) {
    echo "ValueError\n";
}
?>
--EXPECT--
<?xml version="1.0"?>
<container><p>PHP</p></container>
<root>A<div>BC</div>D</root>
ValueError
