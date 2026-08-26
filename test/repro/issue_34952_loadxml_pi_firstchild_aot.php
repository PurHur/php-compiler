<?php
$d = new DOMDocument();
$d->loadXML('<r><?pi data?></r>');
$r = $d->documentElement;
echo 'len=' . $r->childNodes->length . "\n";
$c = $r->firstChild;
echo 'name=' . $c->nodeName . "\n";
echo 'val=' . $c->nodeValue . "\n";
echo 'save=' . $d->saveXML($r) . "\n";
