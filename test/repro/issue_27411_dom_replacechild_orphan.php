<?php

/**
 * #27411 — AOT DOMNode::replaceChild() must orphan the replaced node (parentNode null).
 * Expect: 1|c|orphan
 */
$doc = new DOMDocument();
$a = $doc->createElement('a');
$b = $doc->createElement('b');
$c = $doc->createElement('c');
$doc->appendChild($a);
$a->appendChild($b);
$a->replaceChild($c, $b);
echo $a->childNodes->length, '|', $a->firstChild->nodeName, '|';
echo ($b->parentNode === null) ? 'orphan' : 'parented', "\n";
