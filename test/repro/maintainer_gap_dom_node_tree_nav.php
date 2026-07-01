<?php

declare(strict_types=1);

/** Issue #14335 — DOMNode firstChild/childNodes after loadXML (ext/dom/node.c). */
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$root = $doc->documentElement;
echo $root->firstChild->nodeName, "\n";
echo $root->childNodes->length, "\n";
