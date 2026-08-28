<?php
$d = new DOMDocument();
$e = $d->createElement('x');
$d->appendChild($e);
$e->setAttribute('id', 'foo');
$e->setIdAttribute('id', true);
$attr = $e->getAttributeNode('id');
var_export($attr->isId());
echo "\n";
