<?php
/**
 * #33293 — AOT child textContent must refresh parent/document saveXML (re-#23892).
 *
 * php-src: ext/dom/node.c dom_node_textcontent_write
 */
$d = new DOMDocument();
$d->loadXML('<r><a>old</a><b>keep</b></r>');
$a = $d->documentElement->firstChild;
$a->textContent = 'new';
echo 'a_tc=', var_export($a->textContent, true), "\n";
echo 'save_a=', $d->saveXML($a), "\n";
echo 'save_r=', $d->saveXML($d->documentElement), "\n";
echo 'save=', trim($d->saveXML()), "\n";
