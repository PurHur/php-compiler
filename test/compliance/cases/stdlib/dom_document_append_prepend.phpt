--TEST--
stdlib DOMDocument::append()/prepend() set documentElement (#15344, ext/dom/php_dom.c)
--FILE--
<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->append($root);
echo $doc->documentElement->nodeName, "\n";
$doc2 = new DOMDocument();
$first = $doc2->createElement('a');
$second = $doc2->createElement('b');
$doc2->prepend($first, $second);
echo $doc2->documentElement->nodeName, "\n";
?>
--EXPECT--
root
a
