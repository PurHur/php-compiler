--TEST--
AOT: DOMDocument::createElement() assigns and reads nodeName (#17391, #17672)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$el = $doc->createElement('p');
echo $el->nodeName;
--EXPECT--
p
