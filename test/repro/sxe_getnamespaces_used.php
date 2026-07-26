<?php
$xml = '<root xmlns="urn:def" xmlns:a="urn:a"><child xmlns:b="urn:b"><b:x/></child></root>';
$x = new SimpleXMLElement($xml);
var_export($x->getNamespaces(false));
echo "\n";
var_export($x->getNamespaces(true));
echo "\n";
var_export($x->child->getNamespaces(false));
echo "\n";
