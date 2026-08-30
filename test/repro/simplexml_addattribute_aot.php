<?php
$x = new SimpleXMLElement('<root><child/></root>');
$x->addAttribute('id', '42');
echo $x->asXML();
