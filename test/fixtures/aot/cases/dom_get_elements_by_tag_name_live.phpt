--TEST--
AOT: DOMDocument::getElementsByTagName() live DOMNodeList (#18461, ext/dom/nodelist.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$list = $doc->getElementsByTagName('a');
echo 'before=', $list->length, "\n";
$doc->documentElement->appendChild($doc->createElement('a'));
echo 'after=', $list->length, "\n";
--EXPECT--
before=1
after=2
