--TEST--
AOT: createElement($name, $value) textContent/saveXML must not SIGSEGV (#32292)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$el = $doc->createElement('p', 'hello');
echo $el->textContent, "\n";
echo $el->nodeValue, "\n";
echo $doc->saveXML($el), "\n";
$empty = $doc->createElement('span');
echo var_export($empty->textContent, true), "\n";
--EXPECT--
hello
hello
<p>hello</p>
''
