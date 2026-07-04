--TEST--
stdlib DOMNamedNodeMap / DOMElement::attributes live map (#6189, ext/dom/namednodemap.c)
--FILE--
<?php
var_dump(class_exists('DOMNamedNodeMap', false));
var_dump(class_exists('DOMNodeList', false));
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$root = $doc->documentElement;
$children = $root->childNodes;
var_dump($children instanceof DOMNodeList);
echo 'child_len=', $children->length, "\n";
$el = $doc->createElement('el');
$el->setAttribute('id', 'x');
$map = $el->attributes;
var_dump($map instanceof DOMNamedNodeMap);
echo 'attr_len=', $map->length, "\n";
$attr = $map->getNamedItem('id');
echo $attr->name, '=', $attr->value, "\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
child_len=2
bool(true)
attr_len=1
id=x
