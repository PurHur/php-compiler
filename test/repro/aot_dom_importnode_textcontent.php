<?php
declare(strict_types=1);
// #35801 — deep importNode must seed Element textContent/nodeValue (xmlDocCopyNode).
// Child #text + saveXML were already correct; parent textContent stayed empty.
$doc1 = new DOMDocument();
$doc1->loadXML('<x>text</x>');
$doc2 = new DOMDocument();
$imported = $doc2->importNode($doc1->documentElement, true);
echo 'name=', $imported->nodeName, "\n";
echo 'textContent=', $imported->textContent, "\n";
echo 'nodeValue=', $imported->nodeValue, "\n";
$fc = $imported->firstChild;
echo 'firstChild=', $fc === null ? 'null' : ($fc->nodeName.':'.$fc->nodeValue), "\n";
$doc2->appendChild($imported);
echo 'save=', trim($doc2->saveXML($doc2->documentElement)), "\n";

// Nested markup: textContent aggregates descendant character data.
$d1 = new DOMDocument();
$d1->loadXML('<a><b>hi</b>there</a>');
$d2 = new DOMDocument();
$imp2 = $d2->importNode($d1->documentElement, true);
echo 'nested=', $imp2->textContent, "\n";
