--TEST--
stdlib DOMDocument::createElement() optional $value (#15420, ext/dom/php_dom.c)
--FILE--
<?php
$doc = new DOMDocument();
$node = $doc->createElement('p', 'hello');
echo $node->textContent, "\n";
echo $doc->saveXML($node), "\n";
$empty = $doc->createElement('span');
echo $empty->textContent === '' ? 'empty ok' : 'empty fail', "\n";
?>
--EXPECT--
hello
<p>hello</p>
empty ok
