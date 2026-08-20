--TEST--
AOT: DOMNode::after()/before() on createElement tree — parent is DOMDocument (#32611)
--FILE--
<?php
declare(strict_types=1);

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
