<?php
// #35820 leftover of #35814: SimpleXMLElement property write (sxe_property_write).
$x = new SimpleXMLElement('<root><child/></root>');
$x->child = 'hi';
echo $x->asXML();
