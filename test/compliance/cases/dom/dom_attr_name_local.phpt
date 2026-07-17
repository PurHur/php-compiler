--TEST--
DOMAttr::name is local name for namespaced attrs (#19754, ext/dom/attr.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r xmlns:p="urn:p" p:a="1" b="2"/>');
$map = $d->documentElement->attributes;
for ($i = 0; $i < $map->length; $i++) {
    $n = $map->item($i);
    echo 'name=', $n->name,
        ' nodeName=', $n->nodeName,
        ' localName=', $n->localName,
        ' prefix=', var_export($n->prefix, true),
        "\n";
}
foreach ($map as $k => $attr) {
    echo 'key=', var_export($k, true), ' name=', $attr->name, "\n";
}
$created = $d->createAttributeNS('urn:p', 'p:c');
echo 'created name=', $created->name, ' nodeName=', $created->nodeName, "\n";
?>
--EXPECT--
name=a nodeName=p:a localName=a prefix='p'
name=b nodeName=b localName=b prefix=''
key='a' name=a
key='b' name=b
created name=c nodeName=p:c
