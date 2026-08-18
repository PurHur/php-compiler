--TEST--
DOMDocument::createElement($name, $value) textContent and saveXML (#32292, ext/dom/document.c)
--FILE--
<?php
$doc = new DOMDocument();
$el = $doc->createElement('p', 'hello');
echo 'text=', $el->textContent, "\n";
echo 'nodeValue=', $el->nodeValue, "\n";
echo 'xml=', $doc->saveXML($el), "\n";
$empty = $doc->createElement('span');
echo 'empty=', var_export($empty->textContent, true), "\n";
?>
--EXPECT--
text=hello
nodeValue=hello
xml=<p>hello</p>
empty=''
