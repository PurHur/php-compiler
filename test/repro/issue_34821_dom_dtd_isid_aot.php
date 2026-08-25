<?php
/**
 * #34821 — AOT loadXML DTD ATTLIST ID / xml:id must set Attr::isId() like Zend
 * (php-src ext/dom/attr.c atype == XML_ATTRIBUTE_ID). getElementById already OK (#34696).
 *
 * Keep getElementById out of try{} mains — thin-AOT try+getElementById CFG aborts early
 * (separate from isId stamping).
 */
$d = new DOMDocument();
$d->loadXML('<!DOCTYPE x [<!ATTLIST c id ID #IMPLIED>]><r><c id="t" class="c">x</c></r>');
$el = $d->documentElement->firstChild;
echo 'dtd_chain=', var_export($el->getAttributeNode('id')->isId(), true), "\n";
echo 'class_chain=', var_export($el->getAttributeNode('class')->isId(), true), "\n";
$a = $el->getAttributeNode('id');
echo 'dtd_assigned=', var_export($a->isId(), true), "\n";
echo 'byId=', (($g = $d->getElementById('t')) ? $g->nodeName : 'null'), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><c xml:id="u">y</c></r>');
$el2 = $d2->documentElement->firstChild;
$a2 = $el2->getAttributeNode('xml:id');
echo 'xmlid_assigned=', var_export($a2->isId(), true), "\n";
echo 'xmlid_byId=', (($g2 = $d2->getElementById('u')) ? $g2->nodeName : 'null'), "\n";
