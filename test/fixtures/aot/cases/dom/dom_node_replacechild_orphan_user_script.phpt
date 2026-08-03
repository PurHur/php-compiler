--TEST--
AOT: DOMNode::replaceChild orphans replaced node parentNode (#27411, php-src ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$a = $doc->createElement('a');
$b = $doc->createElement('b');
$c = $doc->createElement('c');
$doc->appendChild($a);
$a->appendChild($b);
$a->replaceChild($c, $b);
echo $a->childNodes->length, '|', $a->firstChild->nodeName, '|';
echo ($b->parentNode === null) ? 'orphan' : 'parented', "\n";
?>
--EXPECT--
1|c|orphan
