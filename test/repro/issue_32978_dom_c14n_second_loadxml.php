<?php
// #32978 — second loadXML must not steal C14N of the first document
$a = new DOMDocument();
$a->loadXML('<r><c/></r>');
$b = new DOMDocument();
$b->loadXML('<x><y/></x>');
echo $a->documentElement->C14N(), "\n";
echo $b->documentElement->C14N(), "\n";
