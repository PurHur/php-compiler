<?php
// C14N on an earlier document must not use a later loadXML literal (#32978).
$a = new DOMDocument();
$a->loadXML('<r><c/></r>');
$b = new DOMDocument();
$b->loadXML('<x><y/></x>');
echo $a->documentElement->C14N();
echo "\n";
