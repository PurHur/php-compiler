<?php
/**
 * #35817 leftover of #35810 / #26863 — SimpleXMLElement dim delete (sxe_prop_dim_delete).
 * php-src: ext/simplexml/sxe.c
 */
$x = new SimpleXMLElement('<root id="42"><child/></root>');
unset($x['id']);
echo $x->asXML();
echo isset($x['id']) ? "set\n" : "unset\n";
