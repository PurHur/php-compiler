<?php
/** Issue #19939 — XMLReader moveToAttributeNo / moveToAttributeNs. */
$x = new XMLReader();
$x->XML('<r a="1"/>');
$x->read();
var_dump($x->moveToAttributeNo(0));
