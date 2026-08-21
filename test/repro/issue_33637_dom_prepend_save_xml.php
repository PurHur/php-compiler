<?php
// #33637 — AOT ParentNode::prepend must not duplicate the child in saveXML.
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$d->documentElement->prepend($d->createElement('z'));
echo $d->saveXML($d->documentElement), "\n";
