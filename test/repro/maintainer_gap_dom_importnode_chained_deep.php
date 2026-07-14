<?php

declare(strict_types=1);

// Issue #18860 — importNode($doc->documentElement->firstChild, true) must import the child node.

$d1 = new DOMDocument();
$d1->loadXML('<root><a id="1"/></root>');
$d2 = new DOMDocument();
$d2->loadXML('<other/>');

$deepTrue = $d2->importNode($d1->documentElement->firstChild, true);
if ('a' !== $deepTrue->nodeName || '1' !== $deepTrue->getAttribute('id')) {
    echo 'fail: importNode(chain, true) expected a id=1, got '
        .$deepTrue->nodeName.' id='.$deepTrue->getAttribute('id')."\n";
    exit(1);
}

$deepFalse = $d2->importNode($d1->documentElement->firstChild, false);
if ('a' !== $deepFalse->nodeName || '1' !== $deepFalse->getAttribute('id')) {
    echo 'fail: importNode(chain, false) expected a id=1, got '
        .$deepFalse->nodeName.' id='.$deepFalse->getAttribute('id')."\n";
    exit(1);
}

echo "ok\n";
