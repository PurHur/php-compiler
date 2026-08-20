<?php
// importNode from a second document then C14N on the target (#32978).
$a = new DOMDocument();
$a->loadXML('<r/>');
$b = new DOMDocument();
$b->loadXML('<x><y/></x>');
$n = $a->importNode($b->documentElement, true);
$a->documentElement->appendChild($n);
echo $a->documentElement->C14N();
echo "\n";
