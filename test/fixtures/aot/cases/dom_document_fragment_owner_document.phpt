--TEST--
AOT: DOMDocumentFragment::$ownerDocument after createDocumentFragment (#20203)
--FILE--
<?php
$doc = new DOMDocument();
$frag = $doc->createDocumentFragment();
echo $frag->ownerDocument === $doc ? "1" : "0";
echo "\n";
?>
--EXPECT--
1
