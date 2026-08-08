--TEST--
AOT: DOMNodeList childNodes live length after appendChild on stored root (#29048, re-#28509/#27044/#19208)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<root><a/><b/></root>');
$root = $d->documentElement;
$list = $root->childNodes;
echo 'before=', $list->length, "\n";
$root->appendChild($d->createElement('c'));
echo 'after=', $list->length, "\n";
--EXPECT--
before=2
after=3
