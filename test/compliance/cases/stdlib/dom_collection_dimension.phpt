--TEST--
stdlib DOMNodeList/DOMNamedNodeMap dimension handlers (#20311, #23304, ext/dom/php_dom.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root a="1" b="2"><child/></root>');
$list = $doc->getElementsByTagName('*');
$map = $doc->documentElement->attributes;

echo $list[0]->nodeName, "\n";
echo isset($list[0]) ? 'yes' : 'no', ' ', isset($list[9]) ? 'yes' : 'no', "\n";
echo $list[9] === null ? 'null' : 'node', ' ', $list['foo'] === null ? 'null' : 'node', "\n";
echo $map['a']->value, ' ', $map[0]->name, "\n";
echo $map['z'] === null ? 'null' : 'node', "\n";
echo $list instanceof ArrayAccess ? 'aa' : 'no-aa', "\n";

try {
    $list[0] = 1;
    echo "write-ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    unset($list[0]);
    echo "unset-list-ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    unset($map['a']);
    echo "unset-map-ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    $map[-1];
    echo "neg-ok\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
root
yes no
null null
1 a
null
no-aa
Cannot use object of type DOMNodeList as array
Cannot use object of type DOMNodeList as array
Cannot use object of type DOMNamedNodeMap as array
must be between 0 and 2147483647
