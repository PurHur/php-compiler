--TEST--
AOT: echo after ?: with DOM createElement() prints branch string not object key (#18784, re-#18052)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$el = $doc->createElement('p');
echo ($el === null) ? "null\n" : "obj\n";
--EXPECT--
obj
