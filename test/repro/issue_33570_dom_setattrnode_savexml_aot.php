<?php
// #33570 — setAttributeNode must sync PROP_USER_SCRIPT_XMLNS_ATTR for saveXML.
$d = new DOMDocument();
$e = $d->createElement('r');
$d->appendChild($e);
$a = $d->createAttribute('id');
$a->value = 'x';
$e->setAttributeNode($a);
echo $d->saveXML($e);
