--TEST--
stdlib DOMElement::insertAdjacentElement() — PHP 8.4 profile (#16865, ext/dom/php_dom.c)
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
$outer = $doc->createElement('p');
$el->insertAdjacentElement('beforebegin', $outer);
$sib = $doc->createElement('em');
$el->insertAdjacentElement('afterend', $sib);
$end = $doc->createElement('i');
$el->insertAdjacentElement('beforeend', $end);
echo preg_replace('/\s+/', '', $doc->saveHTML($root)), "\n";
echo null === $el->insertAdjacentElement('beforeend', null) ? "null\n" : "notnull\n";
try {
    $el->insertAdjacentElement('nope', $inner);
    echo "bad\n";
} catch (ValueError $e) {
    echo "ValueError\n";
}
?>
--EXPECT--
same
<root><p></p><div><b></b><i></i></div><em></em></root>
null
ValueError
