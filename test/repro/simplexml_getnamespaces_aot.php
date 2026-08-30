<?php
$xml = '<root xmlns="urn:def" xmlns:a="urn:a"><child xmlns:b="urn:b"><b:x a:y="1"/></child></root>';
$x = new SimpleXMLElement($xml);
echo 'root_true=';
var_export($x->getNamespaces(true));
echo "\ndoc_root=";
var_export($x->getDocNamespaces(false));
echo "\n";
