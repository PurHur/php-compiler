<?php

declare(strict_types=1);

/**
 * AOT: DocumentFragment firstChild/lastChild after parent appendChild expands
 * the fragment — must be null, not SIGSEGV (#35518 re-#35461).
 *
 * php-src: ext/dom/node.c — dom_node_append_child fragment expand.
 */
$d = new DOMDocument();
$d->loadXML('<r/>');
$frag = $d->createDocumentFragment();
$frag->appendChild($d->createElement('f1'));
$frag->appendChild($d->createElement('f2'));
$d->documentElement->appendChild($frag);
echo 'xml=', $d->saveXML($d->documentElement), "\n";
echo 'len=', $frag->childNodes->length, "\n";
echo 'first=', null === $frag->firstChild ? 'null' : 'obj', "\n";
echo 'last=', null === $frag->lastChild ? 'null' : 'obj', "\n";
