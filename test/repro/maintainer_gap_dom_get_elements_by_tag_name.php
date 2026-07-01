<?php

declare(strict_types=1);

/** Issue #14336 — DOMDocument::getElementsByTagName / DOMNodeList (ext/dom/php_dom.c). */
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$list = $doc->getElementsByTagName('a');
echo $list->length, $list->item(0)->nodeName, "\n";
