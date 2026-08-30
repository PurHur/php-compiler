<?php
// #35827 — AOT SimpleXMLElement::hasChildren (php-src sxe.c; leftover of count #26863).
$x = new SimpleXMLElement('<root><a/></root>');
echo json_encode($x->hasChildren()), "\n";
$y = new SimpleXMLElement('<root/>');
echo json_encode($y->hasChildren()), "\n";
echo gettype($x->hasChildren()), "\n";
