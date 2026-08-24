<?php
/** #34305 — Attr textContent write must update saveXML (re-#33864/#33904). */
$d = new DOMDocument();
$d->loadXML('<r a="v"/>');
$attr = $d->documentElement->getAttributeNode('a');
echo 'tc=', $attr->textContent, "\n";
$attr->textContent = 'w';
echo 'after_tc=', $attr->textContent, "\n";
echo 'nv=', $attr->nodeValue, "\n";
echo 'val=', $attr->value, "\n";
echo $d->saveXML($d->documentElement), "\n";
