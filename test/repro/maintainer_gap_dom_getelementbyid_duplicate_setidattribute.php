<?php
// #25275 — duplicate setIdAttribute: Zend getElementById returns first element (xmlAddID first-wins)
$d = new DOMDocument();
$d->loadXML('<r><a id="x"/><b id="x"/></r>');
$a = $d->documentElement->firstChild;
$b = $a->nextSibling;
$a->setIdAttribute('id', true);
$b->setIdAttribute('id', true);
echo $d->getElementById('x')->nodeName, "\n";
