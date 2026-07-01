--TEST--
dom DOMDocument::saveXML($node) subtree serialization (#14393)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a id="1"/><b/></root>');
$a = $doc->getElementsByTagName('a')->item(0);
echo $doc->saveXML($a), "\n";
echo (int) str_starts_with($doc->saveXML(), '<?xml'), "\n";
--EXPECT--
<a id="1"/>
1
