--TEST--
Dom\Element getInScopeNamespaces/getDescendantNamespaces/rename + NamespaceInfo (#20924)
--SKIPIF--
<?php
if (!class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4 (#20924)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo class_exists('Dom\\NamespaceInfo') ? "nsinfo\n" : "no_nsinfo\n";

$xml = Dom\XMLDocument::createFromString(
    '<root xmlns="urn:def" xmlns:a="urn:a"><a:child xmlns:b="urn:b"/></root>'
);
$root = $xml->documentElement;
$child = $root->firstElementChild;

echo 'root_in=', count($root->getInScopeNamespaces()), "\n";
foreach ($root->getInScopeNamespaces() as $i => $info) {
    echo 'ri', $i, ':', var_export($info->prefix, true), ',', var_export($info->namespaceURI, true), ',',
        ($info->element === $root ? 'root' : 'other'), ',', get_class($info), "\n";
}

echo 'child_in=', count($child->getInScopeNamespaces()), "\n";
foreach ($child->getInScopeNamespaces() as $i => $info) {
    echo 'ci', $i, ':', var_export($info->prefix, true), ',', var_export($info->namespaceURI, true), ',',
        ($info->element === $child ? 'child' : 'other'), "\n";
}

echo 'root_desc=', count($root->getDescendantNamespaces()), "\n";
foreach ($root->getDescendantNamespaces() as $i => $info) {
    echo 'rd', $i, ':', var_export($info->prefix, true), ',', $info->namespaceURI, ',', $info->element->localName, "\n";
}

$el = $xml->createElementNS('urn:old', 'old:x');
$root->appendChild($el);
$el->rename('urn:new', 'new:y');
echo 'ren1:', $el->tagName, ',', $el->namespaceURI, ',', $el->localName, ',', $el->prefix, "\n";
$el->rename(null, 'z');
echo 'ren2:', $el->tagName, ',', var_export($el->namespaceURI, true), ',', $el->localName, ',',
    var_export($el->prefix, true), "\n";

$h = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><div id="x"></div></body></html>'
);
$div = $h->getElementById('x');
try {
    $div->rename('urn:x', 'x:div');
    echo "html_ren_ok\n";
} catch (DOMException $e) {
    echo 'html_ren:', (str_contains($e->getMessage(), 'HTML namespace') ? 'html_ns' : $e->getMessage()), "\n";
}

$plain = Dom\XMLDocument::createFromString('<a><b/></a>');
echo 'plain=', count($plain->documentElement->getInScopeNamespaces()), "\n";

$shadow = Dom\XMLDocument::createFromString('<a xmlns="urn:1"><b xmlns="urn:2"/></a>');
$b = $shadow->documentElement->firstElementChild;
foreach ($b->getInScopeNamespaces() as $i => $info) {
    echo 'sh', $i, ':', var_export($info->prefix, true), ',', $info->namespaceURI, "\n";
}

$alone = Dom\XMLDocument::createEmpty();
$e = $alone->createElementNS('urn:x', 'p:x');
$alone->append($e);
echo 'alone=', count($e->getInScopeNamespaces()), "\n";
?>
--EXPECT--
nsinfo
root_in=2
ri0:NULL,'urn:def',root,Dom\NamespaceInfo
ri1:'a','urn:a',root,Dom\NamespaceInfo
child_in=3
ci0:NULL,'urn:def',child
ci1:'a','urn:a',child
ci2:'b','urn:b',child
root_desc=5
rd0:NULL,urn:def,root
rd1:'a',urn:a,root
rd2:NULL,urn:def,child
rd3:'a',urn:a,child
rd4:'b',urn:b,child
ren1:new:y,urn:new,y,new
ren2:z,NULL,z,NULL
html_ren:html_ns
plain=0
sh0:NULL,urn:2
alone=0
