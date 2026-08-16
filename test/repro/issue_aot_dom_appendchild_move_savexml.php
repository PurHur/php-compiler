<?php
declare(strict_types=1);

/**
 * #31684 — AOT loadXML + appendChild move must reorder saveXML (not concat <tag/>).
 * Also: nextSibling after loadXML must see the following sibling.
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a>1</a><b>2</b></r>');
$r = $doc->documentElement;
$a = $r->firstChild;
$next = $a->nextSibling;
if (null === $next) {
    echo "next=null\n";
} else {
    echo 'next=', $next->nodeName, "\n";
}
$r->appendChild($a);
echo 'xml=', $doc->saveXML($r), "\n";
echo 'len=', $r->childNodes->length, "\n";
echo 'c0=', $r->childNodes->item(0)->nodeName, "\n";
echo 'c1=', $r->childNodes->item(1)->nodeName, "\n";
