--TEST--
ext/dom DOMDocument::saveXML() LIBXML_NOEMPTYTAG expands empty elements (#18507, ext/dom/document.c)
--FILE--
<?php
declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><a/></root>');
$node = $doc->documentElement;

echo str_contains($doc->saveXML(null, LIBXML_NOEMPTYTAG), '<a></a>') ? 'full_ok' : 'full_bad';
echo "\n";
echo str_contains($doc->saveXML($node, LIBXML_NOEMPTYTAG), '<a></a>') ? 'node_ok' : 'node_bad';
echo "\n";
echo str_contains($doc->saveHTML(), '<a></a>') ? 'html_ok' : 'html_bad';
echo "\n";
--EXPECT--
full_ok
node_ok
html_ok
