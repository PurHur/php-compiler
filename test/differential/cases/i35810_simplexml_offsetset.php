<?php
// #35810 leftover of #26863: SimpleXMLElement dim write (sxe_prop_dim_write).
$x = new SimpleXMLElement('<root><child/></root>');
$x['id'] = '42';
echo $x->asXML();
echo $x['id'], "\n";
