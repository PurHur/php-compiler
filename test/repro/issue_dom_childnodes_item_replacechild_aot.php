<?php
declare(strict_types=1);
/**
 * #34436 — replaceChild(createElement, $el->childNodes->item(N)) keeps distinct ARG_SENDs.
 *
 * php-src: ext/dom/node.c dom_node_replace_child
 */
$d = new DOMDocument();
$d->loadXML('<r><a/><c/><d/></r>');
$el = $d->documentElement;
$el->replaceChild($d->createElement('b'), $el->childNodes->item(1));
echo 'xml=', $d->saveXML($el), "\n";
