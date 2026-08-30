<?php
/**
 * #35815 leftover of #35810 — SimpleXMLElement dim unset (sxe_prop_dim_delete).
 * php-src: ext/simplexml/sxe.c
 */
$x = new SimpleXMLElement('<root id="a"><child/></root>');
unset($x['id']);
echo $x->asXML();
