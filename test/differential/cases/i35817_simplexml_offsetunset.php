<?php
// #35817 leftover of #35810: SimpleXMLElement dim delete (sxe_prop_dim_delete).
$x = new SimpleXMLElement('<root id="42"><child/></root>');
unset($x['id']);
echo $x->asXML();
echo isset($x['id']) ? "set\n" : "unset\n";
