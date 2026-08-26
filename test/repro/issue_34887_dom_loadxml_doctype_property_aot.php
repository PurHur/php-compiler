<?php

// #34887 — AOT loadXML must materialize $doc->doctype (peer #34877 saveXML stamp).
$d = new DOMDocument();
$d->loadXML('<!DOCTYPE html><html/>');
echo $d->doctype->name, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd"><html/>');
echo $d2->doctype->publicId, "\n";

$d3 = new DOMDocument();
$d3->loadXML('<r/>');
var_export($d3->doctype);
echo "\n";
