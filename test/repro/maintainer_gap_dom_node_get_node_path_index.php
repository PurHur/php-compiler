<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><child/><child/><child/></root>');
$root = $doc->documentElement;
$paths = [];
for ($c = $root->firstChild; $c; $c = $c->nextSibling) {
    if (XML_ELEMENT_NODE === $c->nodeType) {
        $paths[] = $c->getNodePath();
    }
}

$ok = ['/root/child[1]', '/root/child[2]', '/root/child[3]'] === $paths;

echo $ok ? "ok\n" : ('fail: '.json_encode($paths)."\n");
exit($ok ? 0 : 1);
