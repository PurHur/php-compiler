<?php
/**
 * Minimal repro: DocumentFragment childNodes->length after appendChild.
 */
$src = new DOMDocument();
$f = $src->createDocumentFragment();
$f->appendChild($src->createElement('a'));
echo 'len=', $f->childNodes->length, "\n";
$f->appendChild($src->createTextNode('t'));
echo 'len2=', $f->childNodes->length, "\n";
