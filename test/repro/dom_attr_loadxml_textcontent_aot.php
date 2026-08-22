<?php
// #33904 — loadXML getAttributeNode Attr textContent/nodeValue (re-#33864 createAttribute path).
$d = new DOMDocument();
$d->loadXML('<r a="v"/>');
$attr = $d->documentElement->getAttributeNode('a');
var_dump($attr->value);
var_dump($attr->nodeValue);
var_dump($attr->textContent);
$attr->textContent = 'w';
var_dump($attr->value);
var_dump($attr->textContent);
echo "done\n";
