<?php
// #35823 leftover of #35814: SimpleXMLElement property write (sxe_property_write).
$x = new SimpleXMLElement('<root id="42"><child>a</child></root>');
$x->child = 'hello';
echo $x->asXML();
echo $x->child, "\n";
