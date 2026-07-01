<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><child><leaf/></child></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild->firstChild;

$leafRoot = $leaf->getRootNode();
$docRoot = $doc->getRootNode();

$ok = $leafRoot === $doc
    && $docRoot === $doc
    && $root->getRootNode() === $doc;

echo $ok ? "ok\n" : "fail\n";
exit($ok ? 0 : 1);
