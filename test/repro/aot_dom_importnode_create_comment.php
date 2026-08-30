<?php
/**
 * #35871 leftover of #35098 — importNode(createComment/CDATA/PI).
 * php-src: ext/dom/document.c PHP_METHOD(DOMDocument, importNode)
 *
 * DocumentFragment-with-children remains a follow-up (innerXml stamp / child rebuild).
 */
$src = new DOMDocument();
$dst = new DOMDocument();
$dst->loadXML('<r/>');
$root = $dst->documentElement;

$c = $dst->importNode($src->createComment('hi'), true);
$root->appendChild($c);
echo 'comment name=', $c->nodeName, ' type=', $c->nodeType, ' val=', $c->nodeValue, "\n";
echo 'comment_xml=', $dst->saveXML($root), "\n";

$dst2 = new DOMDocument();
$dst2->loadXML('<r/>');
$cd = $dst2->importNode($src->createCDATASection('x'), true);
$dst2->documentElement->appendChild($cd);
echo 'cdata name=', $cd->nodeName, ' type=', $cd->nodeType, ' val=', $cd->nodeValue, "\n";
echo 'cdata_xml=', $dst2->saveXML($dst2->documentElement), "\n";

$dst3 = new DOMDocument();
$dst3->loadXML('<r/>');
$pi = $dst3->importNode($src->createProcessingInstruction('pi', 'data'), true);
$dst3->documentElement->appendChild($pi);
echo 'pi name=', $pi->nodeName, ' type=', $pi->nodeType, ' val=', $pi->nodeValue, "\n";
echo 'pi_xml=', $dst3->saveXML($dst3->documentElement), "\n";
