<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><child/><child/></root>');
$root = $doc->documentElement;
$paths = [];
for ($c = $root->firstChild; $c; $c = $c->nextSibling) {
    if (XML_ELEMENT_NODE === $c->nodeType) {
        $paths[] = ['path' => $c->getNodePath()];
    }
}

$ok = '[{"path":"\/root\/child[1]"},{"path":"\/root\/child[2]"}]' === json_encode($paths);

echo $ok ? "ok\n" : ('fail: '.json_encode($paths)."\n");
exit($ok ? 0 : 1);
