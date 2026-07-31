--TEST--
Dom\Attr::rename() — QName/NS update + duplicate guard (#21083)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$d = Dom\XMLDocument::createFromString('<r a="1" c="3"/>');
$el = $d->documentElement;
$attr = $el->getAttributeNode('a');
echo 'exists=', method_exists($attr, 'rename') ? 'yes' : 'no', "\n";
echo 'class=', get_class($attr), "\n";

$attr->rename(null, 'b');
echo 'ren1:name=', $attr->name, ',nodeName=', $attr->nodeName, ',local=', $attr->localName, "\n";
echo 'ren1:has_a=', $el->hasAttribute('a') ? '1' : '0', ',has_b=', $el->hasAttribute('b') ? '1' : '0',
    ',val=', $el->getAttribute('b'), "\n";

$attr->rename('urn:x', 'x:b');
echo 'ren2:name=', $attr->name, ',nodeName=', $attr->nodeName, ',ns=', $attr->namespaceURI,
    ',prefix=', $attr->prefix, "\n";
echo 'ren2:has_b=', $el->hasAttribute('b') ? '1' : '0',
    ',ns_val=', $el->getAttributeNS('urn:x', 'b'), "\n";

try {
    $attr->rename(null, 'c');
    echo "dup_ok\n";
} catch (DOMException $e) {
    echo 'dup:', (str_contains($e->getMessage(), 'already exists') ? 'exists' : $e->getMessage()),
        ',code=', $e->getCode(), "\n";
}

$orphan = $d->createAttribute('z');
$orphan->value = '9';
$orphan->rename(null, 'w');
echo 'orphan:name=', $orphan->name, ',nodeName=', $orphan->nodeName, ',val=', $orphan->value, "\n";
?>
--EXPECT--
exists=yes
class=Dom\Attr
ren1:name=b,nodeName=b,local=b
ren1:has_a=0,has_b=1,val=1
ren2:name=x:b,nodeName=x:b,ns=urn:x,prefix=x
ren2:has_b=0,ns_val=1
dup:exists,code=13
orphan:name=w,nodeName=w,val=9
