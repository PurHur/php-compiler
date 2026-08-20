<?php
// C14N after DOM mutation must see the live tree (#32972) — not the loadXML fold.
$dom = new DOMDocument();
$dom->loadXML('<r><c>hi</c></r>');
$el = $dom->documentElement;
$el->appendChild($dom->createElement('z'));
echo 'append=', $el->C14N(), "\n";

$dom2 = new DOMDocument();
$dom2->loadXML('<r><c>hi</c></r>');
$el2 = $dom2->documentElement;
$el2->insertBefore($dom2->createElement('z'), $el2->firstChild);
echo 'insert=', $el2->C14N(), "\n";

$dom3 = new DOMDocument();
$dom3->loadXML('<r><c>hi</c></r>');
$el3 = $dom3->documentElement;
$el3->replaceChild($dom3->createElement('z'), $el3->firstChild);
echo 'replace=', $el3->C14N(), "\n";

$dom4 = new DOMDocument();
$dom4->loadXML('<r><c>hi</c></r>');
$el4 = $dom4->documentElement;
$el4->removeChild($el4->firstChild);
echo 'remove=', $el4->C14N(), "\n";

// Unmutated C14N must still match (fold or runtime).
$dom5 = new DOMDocument();
$dom5->loadXML('<r a="1"><c/></r>');
echo 'plain=', $dom5->documentElement->C14N(), "\n";
