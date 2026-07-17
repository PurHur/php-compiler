<?php

declare(strict_types=1);

/**
 * #20157 — DOMDocument::importNode() for Comment / PI / EntityReference + deep trees.
 * php-src: ext/dom/document.c — dom_document_import_node / xmlDocCopyNode
 */
$src = new DOMDocument();
$src->loadXML('<r/>');
$dst = new DOMDocument();

$comment = $src->createComment('hello');
$importedComment = $dst->importNode($comment, true);
if (!($importedComment instanceof DOMComment) || $importedComment->nodeValue !== 'hello') {
    echo 'fail: comment import';
    exit(1);
}
if ($importedComment->ownerDocument !== $dst) {
    echo 'fail: comment ownerDocument';
    exit(1);
}

$pi = $src->createProcessingInstruction('xml-stylesheet', 'href="a"');
$importedPi = $dst->importNode($pi, true);
if (!($importedPi instanceof DOMProcessingInstruction)
    || $importedPi->nodeName !== 'xml-stylesheet'
    || $importedPi->nodeValue !== 'href="a"'
) {
    echo 'fail: pi import';
    exit(1);
}

$eref = $src->createEntityReference('amp');
$importedEref = $dst->importNode($eref, true);
if (!($importedEref instanceof DOMEntityReference) || $importedEref->nodeName !== 'amp') {
    echo 'fail: entityref import';
    exit(1);
}

$src2 = new DOMDocument();
$src2->loadXML('<r><a><!--c--><?pi d?><b/></a></r>');
$dst2 = new DOMDocument();
$imported = $dst2->importNode($src2->documentElement->firstChild, true);
$xml = $dst2->saveXML($imported);
if ($xml !== '<a><!--c--><?pi d?><b/></a>') {
    echo 'fail: deep import xml=', $xml, "\n";
    exit(1);
}
if ($imported->childNodes->length !== 3) {
    echo 'fail: deep child count=', $imported->childNodes->length, "\n";
    exit(1);
}

echo "ok\n";
