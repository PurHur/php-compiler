--TEST--
DOMNamedNodeMap foreach keys use attribute names (#19417, ext/dom/namednodemap.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r a="1" b="2"/>');
$map = $d->documentElement->attributes;
foreach ($map as $k => $attr) {
    echo 'key_type=', gettype($k), ' key=', var_export($k, true), ' name=', $attr->name, "\n";
}
?>
--EXPECT--
key_type=string key='a' name=a
key_type=string key='b' name=b
