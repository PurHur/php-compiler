<?php
/** Repro #27044 — AOT DOMNodeList live length after appendChild on stored documentElement. */
$d = new DOMDocument();
$d->loadXML('<root><a/><b/></root>');
$root = $d->documentElement;
$list = $root->childNodes;
echo 'before=', $list->length, "\n";
$root->appendChild($d->createElement('c'));
echo 'after=', $list->length, "\n";
