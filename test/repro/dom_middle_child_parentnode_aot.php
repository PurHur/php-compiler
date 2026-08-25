<?php
/**
 * #34590 — middle child parentNode must be the element parent (Zend).
 */
$d = new DOMDocument();
$d->loadXML('<r><a/><a/><a/></r>');
$r = $d->documentElement;
$fc = $r->firstChild;
$mid = $fc->nextSibling;
$i1 = $r->childNodes->item(1);
echo 'fc=', $fc->parentNode ? $fc->parentNode->tagName : 'null', "\n";
echo 'mid=', $mid->parentNode ? $mid->parentNode->tagName : 'null', "\n";
echo 'i1=', $i1->parentNode ? $i1->parentNode->tagName : 'null', "\n";
