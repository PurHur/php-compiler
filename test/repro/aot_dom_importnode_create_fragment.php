<?php
$src = new DOMDocument();
$f = $src->createDocumentFragment();
$f->appendChild($src->createElement('a'));
$f->appendChild($src->createTextNode('t'));
echo 'frag_name=', $f->nodeName, ' type=', $f->nodeType, "\n";
$dst = new DOMDocument();
$n = $dst->importNode($f, true);
echo 'imp_name=', $n->nodeName, ' type=', $n->nodeType, ' len=', $n->childNodes->length, ' xml=', trim($dst->saveXML($n)), "\n";
