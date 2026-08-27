<?php
// #35417 — AOT cloneNode on importNode return must clone the imported node, not
// the source documentElement (leftover of #35373 / #35361 metadata propagation).
$s = new DOMDocument();
$s->loadXML('<r><n a="1"><c>t</c></n></r>');
$t = new DOMDocument();
$t->appendChild($t->createElement('root'));
$node = $t->importNode($s->documentElement->firstChild, true);
$cl = $node->cloneNode(true);
$t->documentElement->appendChild($cl);
echo 'clone_tag=', $cl->nodeName, "\n";
echo 'clone_xml=', $t->saveXML($t->documentElement), "\n";
