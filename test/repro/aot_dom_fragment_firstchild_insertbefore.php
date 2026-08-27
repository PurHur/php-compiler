<?php

declare(strict_types=1);

/**
 * AOT: DocumentFragment firstChild after insertBefore(..., null) (#35461).
 *
 * php-src: ext/dom/node.c dom_node_insert_before.
 */
$d = new DOMDocument();
$f = $d->createDocumentFragment();
$a = $d->createElement('a');
$b = $d->createElement('b');
$f->appendChild($a);
$f->insertBefore($b, null);
$fc = $f->firstChild;
$lc = $f->lastChild;
echo null === $fc ? 'null' : $fc->nodeName, ':', null === $lc ? 'null' : $lc->nodeName, "\n";
echo 'len=', $f->childNodes->length, "\n";
echo $fc->cloneNode(true)->nodeName, "\n";
