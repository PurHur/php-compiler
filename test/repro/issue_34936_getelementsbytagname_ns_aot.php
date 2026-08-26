<?php
$d = new DOMDocument();
$d->loadXML('<r xmlns:x="urn:x"><x:a>hi</x:a></r>');
$n = $d->getElementsByTagName('a');
echo 'len='.$n->length."\n";
$el = $n->item(0);
if ($el) {
    var_export([$el->prefix, $el->localName, $el->namespaceURI, $el->textContent]);
} else {
    var_export(null);
}
echo "\n";
$el2 = $d->getElementsByTagNameNS('urn:x', 'a')->item(0);
if ($el2) {
    var_export([$el2->prefix, $el2->localName, $el2->namespaceURI, $el2->textContent]);
} else {
    var_export(null);
}
echo "\n";
