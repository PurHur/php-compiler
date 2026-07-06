<?php
var_dump(class_exists('DOMNamedNodeMap', false));
var_dump(class_exists('DOMNodeList', false));
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$root = $doc->documentElement;
$children = $root->childNodes;
var_dump($children instanceof DOMNodeList);
echo 'length=', $children->length, "\n";
$attrs = $doc->createElement('el');
$attrs->setAttribute('id', 'x');
$map = $attrs->attributes;
var_dump($map instanceof DOMNamedNodeMap);
echo 'attr_len=', $map->length, "\n";
