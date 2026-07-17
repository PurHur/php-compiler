--TEST--
xmlreader XMLReader::moveToAttributeNo/moveToAttributeNs (#19939, ext/xmlreader/php_xmlreader.c)
--FILE--
<?php
$r = new XMLReader();
$r->XML('<r a="1"/>');
$r->read();
var_export($r->moveToAttributeNo(0));
echo "\n";
echo 'name=', $r->name, ' val=', $r->value, ' type=', $r->nodeType, "\n";
var_export($r->moveToElement());
echo "\n";

$r = new XMLReader();
$r->XML('<root xmlns:ns="urn:x" ns:b="2" a="1"/>');
while ($r->read()) {
    if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'root') {
        var_export($r->moveToAttributeNo(0));
        echo "\n";
        echo 'no0=', $r->name, '=', $r->value, "\n";
        $r->moveToElement();
        var_export($r->moveToAttributeNo(99));
        echo "\n";
        var_export($r->moveToAttributeNs('b', 'urn:x'));
        echo "\n";
        echo 'ns=', $r->name, '=', $r->value, "\n";
        $r->moveToElement();
        var_export($r->moveToAttributeNs('b', 'urn:other'));
        echo "\n";
        try {
            $r->moveToAttributeNs('', 'urn:x');
        } catch (ValueError $e) {
            echo 'emptyName=', $e->getMessage(), "\n";
        }
        try {
            $r->moveToAttributeNs('b', '');
        } catch (ValueError $e) {
            echo 'emptyNs=', $e->getMessage(), "\n";
        }
        break;
    }
}
?>
--EXPECT--
true
name=a val=1 type=2
true
true
no0=xmlns:ns=urn:x
false
true
ns=ns:b=2
false
emptyName=XMLReader::moveToAttributeNs(): Argument #1 ($name) cannot be empty
emptyNs=XMLReader::moveToAttributeNs(): Argument #2 ($namespace) cannot be empty
