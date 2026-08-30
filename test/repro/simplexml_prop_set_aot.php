<?php
/**
 * #35824 leftover of #35814 — SimpleXMLElement property write (sxe_property_write / __set).
 * php-src: ext/simplexml/sxe.c
 */
$x = new SimpleXMLElement('<root><child>old</child></root>');
$x->child = 'new';
echo $x->asXML();
echo (string) $x->child, "\n";
