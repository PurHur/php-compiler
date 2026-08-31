<?php
/**
 * XPath query() NodeList length is a snapshot — DOM mutations must not shrink it.
 *
 * php-src: ext/dom/nodelist.c (XPath node-set fixed at query time).
 */
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$x = new DOMXPath($d);
$nl = $x->query('//*');
echo 'before=', $nl->length, "\n";
$d->documentElement->removeChild($d->documentElement->firstChild);
echo 'after=', $nl->length, "\n";
echo 'item1=', ($nl->item(1) ? $nl->item(1)->nodeName : 'null'), "\n";
for ($i = 0; $i < $nl->length; $i++) {
    $n = $nl->item($i);
    echo 'item', $i, '=', ($n ? $n->nodeName : 'null'), "\n";
}
