<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><child><leaf/></child></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild->firstChild;

$paths = [
    'doc' => $doc->getNodePath(),
    'root' => $root->getNodePath(),
    'leaf' => $leaf->getNodePath(),
];

$ok = '/' === $paths['doc']
    && '/root' === $paths['root']
    && '/root/child/leaf' === $paths['leaf'];

echo $ok ? "ok\n" : ('fail: '.json_encode($paths)."\n");
exit($ok ? 0 : 1);
