--TEST--
AOT: createElement+appendChild saveXML matches xmlDocDumpMemory (#32361)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$a = $doc->createElement('a', '1');
$b = $doc->createElement('b', '2');
$root->appendChild($a);
$root->appendChild($b);
echo $root->tagName, "\n";
echo $root->firstChild->tagName, "\n";
echo $doc->saveXML($root), "\n";
echo $doc->saveXML();
--EXPECT--
root
a
<root><a>1</a><b>2</b></root>
<?xml version="1.0"?>
<root><a>1</a><b>2</b></root>
