<?php
// Regression: setIdAttribute then replaceChild while holding live childNodes —
// syncElementIdMapProperty HashTable::add gets int key (php-src ext/dom/node.c).
$doc = new DOMDocument();
$doc->loadXML('<root><a id="1"><b id="2">t</b><c/></a></root>');
$a = $doc->documentElement->firstChild;
$b = $a->firstChild;
$a->setIdAttribute('id', true);
$b->setIdAttribute('id', true);
echo 'id1=', $doc->getElementById('1') ? 'Y' : 'N', ' id2=', $doc->getElementById('2') ? 'Y' : 'N', "\n";
$list = $a->childNodes;
$old = $a->firstChild;
$neu = $doc->createElement('x');
$a->replaceChild($neu, $old);
echo 'len=', $list->length, ' item0=', $list->item(0)->nodeName, "\n";
echo "OK\n";
