<?php
/**
 * #34780 — AOT: Element::getElementsByTagName item() must filter by tag and
 * exclude self for '*' (php-src ext/dom/element.c descendants-only).
 */
$d = new DOMDocument();
$d->loadXML('<root><a/><b/><c/></root>');
$root = $d->documentElement;

$leb = $root->getElementsByTagName('b');
echo 'EL_b=', ($leb->item(0) ? $leb->item(0)->nodeName : 'null'), "\n";

$les = $root->getElementsByTagName('*');
echo 'EL_star=';
for ($i = 0; $i < $les->length; $i++) {
    $n = $les->item($i);
    echo ($n ? $n->nodeName : '?'), ',';
}
echo "\n";

$n = $d->createElement('n');
$old = $root->getElementsByTagName('b')->item(0);
$root->replaceChild($n, $old);
echo 'after_replace=', $d->saveXML($root), "\n";
