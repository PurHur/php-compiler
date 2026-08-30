<?php
/**
 * #35834 leftover of #35820 / #35814 — nested SimpleXMLElement property write
 * (sxe_property_write). php-src: ext/simplexml/sxe.c
 *
 * `$x->child =` already host-folds (#35820). `$x->a->b =` FETCH_OBJ on the child
 * view used to skip tryPropSet (silent no-op; (string)$x->a->b empty).
 */
$x = new SimpleXMLElement('<root><a><b>old</b></a></root>');
$x->a->b = 'new';
echo $x->asXML();
echo (string) $x->a->b;
