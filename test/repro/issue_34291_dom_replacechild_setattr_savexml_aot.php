<?php

declare(strict_types=1);

/**
 * #34291 — AOT createElement+setAttribute+replaceChild must keep attrs in saveXML.
 *
 * Zend/VM: <r><a/><x id="x" k="v"/><c/></r>
 * Was AOT: <r><a/><x/><c/></r> (syncUserScriptInnerXml omitted attr suffix).
 *
 * @see php-src ext/dom/node.c dom_node_replace_child
 * @see php-src ext/dom/element.c setAttribute
 */
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$r = $d->documentElement;
$old = $r->childNodes->item(1);
$n = $d->createElement('x');
$n->setAttribute('id', 'x');
$n->setAttribute('k', 'v');
$r->replaceChild($n, $old);
echo trim($d->saveXML($r)), "\n";
echo $n->getAttribute('id'), '|', $n->getAttribute('k'), "\n";
