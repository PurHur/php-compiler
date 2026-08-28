<?php
$d = new DOMDocument();
$e = $d->createElement('x');
$d->appendChild($e);
$e->setAttribute('id2', 'baz');
$a3 = $e->getAttributeNode('id2');
$e->setIdAttributeNode($a3, true);
var_export($a3->isId());
echo "\n";
