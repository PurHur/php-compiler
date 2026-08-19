--TEST--
DOM ChildNode::after() AOT segfault — parent is DOMDocument, not DOMElement (#32611; ext/dom/php_dom.c)
--FILE--
<?php
$doc = new DOMDocument();
$p = $doc->createElement('p');
$doc->appendChild($p);
$span = $doc->createElement('span');
$p->after($span);
echo "after=ok\n";

$doc2 = new DOMDocument();
$a = $doc2->createElement('a');
$doc2->appendChild($a);
$b = $doc2->createElement('b');
$a->before($b);
echo "before=ok\n";
--EXPECT--
after=ok
before=ok
