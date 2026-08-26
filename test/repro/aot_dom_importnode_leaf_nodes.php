<?php
// #35098 — AOT importNode(Comment/CDATA/PI) must copy leaf nodes (php-src xmlDocCopyNode).
// Leftover of #35043 (text-only materialize).

// create* path (peer #35043 createTextNode):
$src = new DOMDocument();
$dst = new DOMDocument();
$dst->loadXML('<r/>');
$c = $dst->importNode($src->createComment('c'), true);
echo 'create_comment_type=', $c->nodeType, ' name=', $c->nodeName, ' val=', $c->nodeValue, "\n";
$dst->documentElement->appendChild($c);
echo 'create_comment_xml=', trim($dst->saveXML($dst->documentElement)), "\n";

$dst = new DOMDocument();
$dst->loadXML('<r/>');
$cd = $dst->importNode($src->createCDATASection('x'), true);
echo 'create_cdata_type=', $cd->nodeType, ' name=', $cd->nodeName, ' val=', $cd->nodeValue, "\n";
$dst->documentElement->appendChild($cd);
echo 'create_cdata_xml=', trim($dst->saveXML($dst->documentElement)), "\n";

$dst = new DOMDocument();
$dst->loadXML('<r/>');
$pi = $dst->importNode($src->createProcessingInstruction('pi', 'data'), true);
echo 'create_pi_type=', $pi->nodeType, ' name=', $pi->nodeName, ' val=', $pi->nodeValue, "\n";
$dst->documentElement->appendChild($pi);
echo 'create_pi_xml=', trim($dst->saveXML($dst->documentElement)), "\n";

// loadXML sibling path (assigned temps so ChildIndex survives ARG_SEND):
$d1 = new DOMDocument();
$d1->loadXML('<r><!--c--><![CDATA[x]]><?pi data?><e/></r>');
$root = $d1->documentElement;
$n0 = $root->firstChild;
$n1 = $n0->nextSibling;
$n2 = $n1->nextSibling;

$d2 = new DOMDocument();
$d2->loadXML('<r/>');
$imp0 = $d2->importNode($n0, true);
echo 'sib_comment_type=', $imp0->nodeType, ' name=', $imp0->nodeName, ' val=', $imp0->nodeValue, "\n";
$d2->documentElement->appendChild($imp0);
echo 'sib_comment_xml=', trim($d2->saveXML($d2->documentElement)), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r/>');
$imp1 = $d2->importNode($n1, true);
echo 'sib_cdata_type=', $imp1->nodeType, ' name=', $imp1->nodeName, ' val=', $imp1->nodeValue, "\n";
$d2->documentElement->appendChild($imp1);
echo 'sib_cdata_xml=', trim($d2->saveXML($d2->documentElement)), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r/>');
$imp2 = $d2->importNode($n2, true);
echo 'sib_pi_type=', $imp2->nodeType, ' name=', $imp2->nodeName, ' val=', $imp2->nodeValue, "\n";
$d2->documentElement->appendChild($imp2);
echo 'sib_pi_xml=', trim($d2->saveXML($d2->documentElement)), "\n";
