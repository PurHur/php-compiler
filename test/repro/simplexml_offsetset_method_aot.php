<?php
/**
 * Leftover of #35810 — SimpleXMLElement::offsetSet/offsetExists method form
 * (php-src ext/simplexml/sxe.c zim_simplexmlelement_offsetSet).
 */
$x = new SimpleXMLElement('<root id="42"><child/></root>');
$x->offsetSet('k', 'v');
echo $x->asXML();
echo $x->offsetExists('k') ? "oe_k=1\n" : "oe_k=0\n";
echo $x->offsetExists('id') ? "oe_id=1\n" : "oe_id=0\n";
