<?php

// #34910 — AOT loadXML ParentNode element-nav props must match Zend (no SIGSEGV).
$d = new DOMDocument();
$d->loadXML('<r><c/></r>');
echo null === $d->firstElementChild ? 'null' : $d->firstElementChild->nodeName, "\n";
echo null === $d->lastElementChild ? 'null' : $d->lastElementChild->nodeName, "\n";
echo (int) $d->childElementCount, "\n";

$empty = new DOMDocument();
echo null === $empty->firstElementChild ? 'null' : 'obj', "\n";
echo null === $empty->lastElementChild ? 'null' : 'obj', "\n";
echo (int) $empty->childElementCount, "\n";
