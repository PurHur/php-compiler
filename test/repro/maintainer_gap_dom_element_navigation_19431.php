<?php
/** Repro #19431 — ParentNode / NonDocumentTypeChildNode element navigation. */
$dom = new DOMDocument();
$body = $dom->createElement('body');
$dom->appendChild($body);
$p1 = $dom->createElement('p');
$p2 = $dom->createElement('div');
$body->append('t', $p1, $p2);
echo 'firstElementChild=', $body->firstElementChild->nodeName, "\n";
echo 'lastElementChild=', $body->lastElementChild->nodeName, "\n";
echo 'childElementCount=', $body->childElementCount, "\n";
echo 'nextElementSibling=', $p1->nextElementSibling->nodeName, "\n";
echo 'previousElementSibling=', $p2->previousElementSibling->nodeName, "\n";
