<?php
// #34995 — NS item(0) identity with childNodes (re-#34983).
$d = new DOMDocument();
$d->loadXML('<r xmlns:n="u"><n:a/><n:a/></r>');
$l = $d->getElementsByTagNameNS('u', 'a');
$fromList = $l->item(0);
$fromChild = $d->documentElement->childNodes->item(0);
echo ($fromList->parentNode ? 'p' : 'np'), '|';
echo ($fromList->isSameNode($fromChild) ? 'same' : 'diff'), '|';
echo ($fromList->namespaceURI ?? 'null'), '|', ($fromList->localName ?? 'null'), "\n";
