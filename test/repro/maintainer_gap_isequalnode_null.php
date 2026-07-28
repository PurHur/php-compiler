<?php

// #24462 — DOMNode::isEqualNode(?DOMNode) null → false (php-src ext/dom/node.c)

$doc = new DOMDocument();
$doc->loadXML('<root><a id="1"/><b id="2"/></root>');
$a = $doc->documentElement->firstChild;
$b = $a->cloneNode(true);
$c = $doc->documentElement->lastChild;

echo 'eq=', (int) $a->isEqualNode($b), "\n";
echo 'ne=', (int) $a->isEqualNode($c), "\n";

try {
    $r = $a->isEqualNode(null);
    echo 'null=', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'null=', get_class($e), ': ', $e->getMessage(), "\n";
}

if (class_exists('Dom\\XMLDocument')) {
    $xd = Dom\XMLDocument::createFromString('<root><a/></root>');
    $n = $xd->documentElement->firstChild;
    try {
        $r2 = $n->isEqualNode(null);
        echo 'dom_null=', var_export($r2, true), "\n";
    } catch (Throwable $e) {
        echo 'dom_null=', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
