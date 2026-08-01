--TEST--
stdlib DOMElement::insertAdjacentHTML() — PHP 8.5+ profile (#26063, re-#16128, ext/dom/php_dom.stub.php)
--FILE--
<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$el = $doc->createElement('div');
$root->appendChild($el);
$el->insertAdjacentHTML('afterbegin', '<b>x</b>');
$el->insertAdjacentHTML('beforeend', '<i>y</i>');
echo preg_replace('/\s+/', '', $doc->saveHTML($el)), "\n";
$sib = $doc->createElement('sib');
$root->appendChild($sib);
$el->insertAdjacentHTML('beforebegin', '<p>bb</p>');
$el->insertAdjacentHTML('afterend', '<em>ae</em>');
echo preg_replace('/\s+/', '', $doc->saveHTML($root)), "\n";
try {
    $el->insertAdjacentHTML('nope', 'x');
    echo "bad\n";
} catch (ValueError $e) {
    echo "ValueError\n";
}
?>
--EXPECT--
<div><b>x</b><i>y</i></div>
<root><p>bb</p><div><b>x</b><i>y</i></div><em>ae</em><sib></sib></root>
ValueError
