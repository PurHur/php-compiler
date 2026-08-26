<?php
/**
 * #34936 — getElementsByTagName(NS) after loadXML namespaced children.
 *
 * Avoid `$el ? [$el->prop, ...] : null` in one expression — AOT miscompiles that
 * ternary+array shape; read props into locals first (Done-when still matches Zend).
 */
$d = new DOMDocument();
$d->loadXML('<r xmlns:x="urn:x"><x:a>hi</x:a></r>');

$el2 = $d->getElementsByTagNameNS('urn:x', 'a')->item(0);
if ($el2 instanceof DOMElement) {
    $a = [$el2->prefix, $el2->localName, $el2->namespaceURI, $el2->textContent];
    var_export($a);
} else {
    var_export(null);
}
echo "\n";

$n = $d->getElementsByTagName('a');
echo 'len='.$n->length."\n";
$el = $n->item(0);
if ($el instanceof DOMElement) {
    $b = [$el->prefix, $el->localName, $el->namespaceURI, $el->textContent];
    var_export($b);
} else {
    var_export(null);
}
echo "\n";
