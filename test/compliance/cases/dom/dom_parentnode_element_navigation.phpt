--TEST--
DOM ParentNode / NonDocumentTypeChildNode element navigation (#19431, ext/dom/parentnode.c)
--FILE--
<?php
$dom = new DOMDocument();
$body = $dom->createElement('body');
$dom->appendChild($body);
$p1 = $dom->createElement('p');
$p2 = $dom->createElement('div');
$body->append('t', $p1, $p2);
echo 'first=', $body->firstElementChild->nodeName, "\n";
echo 'last=', $body->lastElementChild->nodeName, "\n";
echo 'count=', $body->childElementCount, "\n";
echo 'next=', $p1->nextElementSibling->nodeName, "\n";
echo 'prev=', $p2->previousElementSibling->nodeName, "\n";
echo 'childNodes=', $body->childNodes->length, "\n";
?>
--EXPECT--
first=p
last=div
count=2
next=div
prev=p
childNodes=3
