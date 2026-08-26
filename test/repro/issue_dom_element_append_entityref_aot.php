<?php
declare(strict_types=1);

/**
 * #35148 — AOT Element::appendChild(createEntityReference) must not SIGSEGV.
 * php-src ext/dom/document.c createEntityReference → xmlNewReference;
 * ext/dom/node.c dom_node_append_child; parentnode.c element-nav skips non-elements.
 */
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$er = $d->createEntityReference('amp');
echo 'type=', $er->nodeType, ' name=', $er->nodeName, "\n";
$r->appendChild($er);
echo 'len=', $r->childNodes->length, ' fc=', $r->firstChild->nodeName, "\n";
echo 'xml=', trim($d->saveXML($r)), "\n";
