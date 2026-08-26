<?php

$d = new DOMDocument();
$r = $d->appendChild($d->createElement("r"));
$x = $r->appendChild($d->createElement("x"));
$y = $r->appendChild($d->createElement("y"));
$z = $r->appendChild($d->createElement("z"));
$r->removeChild($y);
echo "xnext=".$x->nextSibling->nodeName."\n";
echo "save=".$d->saveXML($r)."\n";

