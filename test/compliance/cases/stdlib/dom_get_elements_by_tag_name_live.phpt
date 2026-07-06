--TEST--
stdlib DOMNodeList live getElementsByTagName (#6189, ext/dom/nodelist.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$list = $doc->getElementsByTagName('a');
echo 'before=', $list->length, "\n";
$doc->documentElement->appendChild($doc->createElement('a'));
echo 'after=', $list->length, "\n";
?>
--EXPECT--
before=1
after=2
