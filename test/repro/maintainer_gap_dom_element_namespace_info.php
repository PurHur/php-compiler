<?php
// Repro #20924 — Dom\NamespaceInfo + Element namespace APIs (php-src element.c)
echo class_exists('Dom\\NamespaceInfo') ? "nsinfo\n" : "no_nsinfo\n";
echo method_exists('Dom\\Element', 'getInScopeNamespaces') ? "in_scope_fn\n" : "no_in_scope_fn\n";
echo method_exists('Dom\\Element', 'getDescendantNamespaces') ? "desc_fn\n" : "no_desc_fn\n";
echo method_exists('Dom\\Element', 'rename') ? "rename_fn\n" : "no_rename_fn\n";

$xml = Dom\XMLDocument::createFromString(
    '<root xmlns="urn:def" xmlns:a="urn:a"><a:child xmlns:b="urn:b"/></root>'
);
$root = $xml->documentElement;
$child = $root->firstElementChild;

echo 'root_in=', count($root->getInScopeNamespaces()), "\n";
foreach ($root->getInScopeNamespaces() as $i => $info) {
    echo 'ri', $i, ':', var_export($info->prefix, true), ',', var_export($info->namespaceURI, true), ',',
        ($info->element === $root ? 'root' : 'other'), "\n";
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

// createElementNS alone — no xmlns attribute → empty in-scope (php-src modern DOM)
$alone = Dom\XMLDocument::createEmpty();
$e = $alone->createElementNS('urn:x', 'p:x');
$alone->append($e);
echo 'alone=', count($e->getInScopeNamespaces()), "\n";
