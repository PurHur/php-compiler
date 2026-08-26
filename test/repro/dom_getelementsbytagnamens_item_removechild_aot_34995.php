<?php
// #34995 — getElementsByTagNameNS()->item() must return the live tree node (re-#34983).
$d = new DOMDocument();
$d->loadXML('<r xmlns:n="u"><n:a/><n:a/><n:a/></r>');
$l = $d->getElementsByTagNameNS('u', 'a');
echo $l->length, '|';
$fromList = $l->item(1);
$fromChild = $d->documentElement->childNodes->item(1);
echo ($fromList->parentNode ? $fromList->parentNode->nodeName : 'null'), '|';
echo ($fromList->isSameNode($fromChild) ? 'same' : 'diff'), '|';
$d->documentElement->removeChild($fromList);
echo $l->length, '|', $d->getElementsByTagNameNS('u', 'a')->length, "\n";
