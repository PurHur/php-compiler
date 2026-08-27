<?php

declare(strict_types=1);

/**
 * AOT: DocumentFragment firstChild after appendChild(createElement) (#35461).
 *
 * php-src: ext/dom/node.c dom_node_append_child / child edge accessors.
 */
$d = new DOMDocument();
$f = $d->createDocumentFragment();
$a = $d->createElement('a');
$f->appendChild($a);
$fc = $f->firstChild;
echo null === $fc ? 'null' : $fc->nodeName, "\n";
$c = $fc->cloneNode(true);
echo $c->nodeName, "\n";
echo 'len=', $f->childNodes->length, "\n";
