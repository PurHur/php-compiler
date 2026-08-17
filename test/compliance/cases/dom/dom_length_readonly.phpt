--TEST--
DOM NodeList/NamedNodeMap/CharacterData::$length is read-only (#31707, ext/dom)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r a="1"><a/></r>');
$list = $d->getElementsByTagName('*');
$before = $list->length;
try {
    $list->length = 99;
    echo "list_ok\n";
} catch (Error $e) {
    echo $e->getMessage(), " kept=", ($list->length === $before ? 'yes' : 'no'), "\n";
}
$map = $d->documentElement->attributes;
$beforeMap = $map->length;
try {
    $map->length = 99;
    echo "map_ok\n";
} catch (Error $e) {
    echo $e->getMessage(), " kept=", ($map->length === $beforeMap ? 'yes' : 'no'), "\n";
}
$text = $d->createTextNode('hi');
try {
    $text->length = 9;
    echo "text_ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot write read-only property DOMNodeList::$length kept=yes
Cannot write read-only property DOMNamedNodeMap::$length kept=yes
Cannot write read-only property DOMText::$length
