<?php

declare(strict_types=1);

/**
 * Issue #15293 — DOMNode::insertBefore() moves DocumentFragment children before ref node.
 */

$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$a = $doc->createElement('a');
$b = $doc->createElement('b');
$root->appendChild($a);
$root->appendChild($b);

$frag = $doc->createDocumentFragment();
$frag->appendChild($doc->createElement('x'));
$frag->appendChild($doc->createElement('y'));
$root->insertBefore($frag, $b);

$names = [];
for ($i = 0; $i < $root->childNodes->length; ++$i) {
    $names[] = $root->childNodes->item($i)->nodeName;
}
$expect = ['a', 'x', 'y', 'b'];
if ($expect !== $names) {
    echo 'fail: child order ', implode(',', $names), ' expected ', implode(',', $expect), "\n";
    exit(1);
}
if (0 !== $frag->childNodes->length) {
    echo 'fail: fragment not emptied, length=', $frag->childNodes->length, "\n";
    exit(1);
}

echo "ok\n";
