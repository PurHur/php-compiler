<?php

declare(strict_types=1);

/**
 * #33610 — AOT createElement-only replaceChild must keep siblings in saveXML.
 *
 * Zend/VM: <r><a/><n/><c/></r> + nextSibling walk a,n,c.
 * Was AOT saveXML <r><n/></r> (INNER_XML overwrite after LiveSlots rebuild).
 *
 * @see php-src ext/dom/node.c dom_node_replace_child
 */
$d = new DOMDocument();
$r = $d->createElement('r');
$d->appendChild($r);
$a = $d->createElement('a');
$b = $d->createElement('b');
$c = $d->createElement('c');
$r->appendChild($a);
$r->appendChild($b);
$r->appendChild($c);
$n = $d->createElement('n');
$r->replaceChild($n, $b);
$cur = $r->firstChild;
$ids = [];
while ($cur) {
    $ids[] = $cur->tagName;
    $cur = $cur->nextSibling;
}
echo implode(',', $ids), "\n";
echo trim($d->saveXML($r)), "\n";
