--TEST--
AOT: createElementNS($ns, $name, $value) textContent/nodeValue/saveXML must not SIGSEGV (#32302)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$el = $doc->createElementNS('http://example.com', 'ex:item', 'hello');
echo $el->textContent, "\n";
echo $el->nodeValue, "\n";
echo $doc->saveXML($el), "\n";
$empty = $doc->createElementNS('http://example.com', 'ex:empty');
echo var_export($empty->textContent, true), "\n";
--EXPECT--
hello
hello
<ex:item xmlns:ex="http://example.com">hello</ex:item>
''
