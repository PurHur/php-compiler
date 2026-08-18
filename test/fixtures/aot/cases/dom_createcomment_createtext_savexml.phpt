--TEST--
AOT: createComment/createTextNode saveXML must not SIGSEGV (#32315)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$c = $doc->createComment('hi');
echo $c->nodeName, '|', $c->nodeValue, '|', $c->textContent, "\n";
echo $doc->saveXML($c), "\n";
$t = $doc->createTextNode('hi');
echo $t->nodeName, '|', $t->nodeValue, '|', $t->textContent, "\n";
echo $doc->saveXML($t), "\n";
$el = $doc->createElement('p', 'hello');
echo $doc->saveXML($el), "\n";
--EXPECT--
#comment|hi|hi
<!--hi-->
#text|hi|hi
hi
<p>hello</p>
