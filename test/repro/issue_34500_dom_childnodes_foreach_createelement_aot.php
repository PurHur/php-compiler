<?php
/**
 * AOT: foreach over createElement-only childNodes (#34500).
 * php-src: ext/dom/nodelist.c — live InternalIterator; no loadXML required.
 */
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('root'));
$r->appendChild($d->createElement('a'));
$b = $r->appendChild($d->createElement('b'));
$r->appendChild($d->createElement('c'));
foreach ($r->childNodes as $n) {
    echo $n->nodeName, ',';
}
echo "\n";
$x = $d->createElement('x');
$r->replaceChild($x, $b);
foreach ($r->childNodes as $n) {
    echo $n->nodeName, ',';
}
echo "\n";
