--TEST--
xmlreader XMLReader moveToAttribute/moveToElement/next (#19395, ext/xmlreader/php_xmlreader.c)
--FILE--
<?php
$r = new XMLReader();
$r->XML('<r><a id="1" x="2">t</a><b/></r>');
$r->read(); // r
$r->read(); // a
echo 'name=', $r->name, ' attrCount=', $r->attributeCount, "\n";
var_export($r->moveToAttribute('id'));
echo "\n";
echo 'attrName=', $r->name, ' val=', $r->value, ' type=', $r->nodeType, "\n";
var_export($r->moveToElement());
echo "\n";
var_export($r->moveToFirstAttribute());
echo "\n";
echo 'first=', $r->name, "\n";
var_export($r->moveToNextAttribute());
echo "\n";
echo 'nextAttr=', $r->name, "\n";
$r->moveToElement();
var_export($r->next('b'));
echo "\n";
echo 'afterNext=', $r->name, "\n";
?>
--EXPECT--
name=a attrCount=2
true
attrName=id val=1 type=2
true
true
first=id
true
nextAttr=x
true
afterNext=b
