<?php
// leftover #35098 — createComment then importNode (not loadXML firstChild)
$src = new DOMDocument();
$c = $src->createComment('hi');
echo 'src_class=', get_class($c), ' name=', $c->nodeName, ' type=', $c->nodeType, ' val=', $c->nodeValue, "\n";
$dst = new DOMDocument();
$n = $dst->importNode($c, true);
echo 'imp_class=', get_class($n), ' name=', $n->nodeName, ' type=', $n->nodeType, ' val=', $n->nodeValue, "\n";
echo 'instanceof=', ($n instanceof DOMComment ? 'yes' : 'no'), "\n";
$dst->appendChild($dst->createElement('r'))->appendChild($n);
echo 'xml=', trim($dst->saveXML($dst->documentElement)), "\n";
