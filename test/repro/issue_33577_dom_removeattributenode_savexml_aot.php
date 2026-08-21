<?php
// AOT: removeAttributeNode must clear PROP_USER_SCRIPT_XMLNS_ATTR for saveXML (#33577 peer #33570/#33143).
$d = new DOMDocument();
$e = $d->createElement('r');
$d->appendChild($e);
$a = $d->createAttribute('id');
$a->value = 'x';
$e->appendChild($a);
$e->removeAttributeNode($e->getAttributeNode('id'));
echo $d->saveXML($e), '|', $e->hasAttribute('id') ? 'y' : 'n';
