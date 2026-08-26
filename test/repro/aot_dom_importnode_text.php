<?php
// #35043 — importNode(DOMText) must copy text, not invent an element
$src = new DOMDocument();
$t = $src->createTextNode("hi");
$dst = new DOMDocument();
$dst->loadXML("<r/>");
$imp = $dst->importNode($t, true);
echo "createText nodeName=", $imp->nodeName, " nodeType=", $imp->nodeType, " data=", $imp->data ?? "", "\n";
$dst->documentElement->appendChild($imp);
echo "createText xml=", $dst->saveXML($dst->documentElement), "\n";

$s2 = new DOMDocument();
$s2->loadXML("<r>hello</r>");
$d2 = new DOMDocument();
$d2->loadXML("<out/>");
// firstChild of <r>hello</r> is the text node when no element child
$textSibling = $s2->documentElement->firstChild;
$imp2 = $d2->importNode($textSibling, true);
echo "firstChildText nodeName=", $imp2->nodeName, " nodeType=", $imp2->nodeType, " data=", $imp2->data ?? $imp2->textContent, "\n";
$d2->documentElement->appendChild($imp2);
echo "firstChildText xml=", $d2->saveXML($d2->documentElement), "\n";

$s3 = new DOMDocument();
$s3->loadXML("<r><e/>hello</r>");
$d3 = new DOMDocument();
$d3->loadXML("<out/>");
$ns = $s3->documentElement->firstChild->nextSibling;
$imp3 = $d3->importNode($ns, true);
echo "nextSiblingText nodeName=", $imp3->nodeName, " nodeType=", $imp3->nodeType, " data=", $imp3->data ?? $imp3->textContent, "\n";
$d3->documentElement->appendChild($imp3);
echo "nextSiblingText xml=", $d3->saveXML($d3->documentElement), "\n";
