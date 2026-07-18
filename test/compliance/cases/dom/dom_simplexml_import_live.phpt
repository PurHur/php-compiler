--TEST--
DOM/SimpleXML: import bridges share live node identity (#20137/#20697, ext/dom/node.c)
--FILE--
<?php
$sxe = simplexml_load_string('<root><a>1</a></root>');
$node = dom_import_simplexml($sxe->a);
$node->textContent = '2';
echo (string) $sxe->a, "\n";
$node->setAttribute('k', 'v');
echo (string) $sxe->a['k'], "\n";

$d = new DOMDocument();
$el = $d->createElement('b', '1');
$d->appendChild($el);
$back = simplexml_import_dom($el);
$el->textContent = '3';
echo (string) $back, "\n";
$el->setAttribute('id', 'z');
echo (string) $back['id'], "\n";
?>
--EXPECT--
2
v
3
z
