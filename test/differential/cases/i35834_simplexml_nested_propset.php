<?php
// #35834 leftover of #35820: nested SimpleXMLElement property write (sxe_property_write).
$x = new SimpleXMLElement('<root><a><b>old</b></a></root>');
$x->a->b = 'new';
echo $x->asXML();
echo (string) $x->a->b;
