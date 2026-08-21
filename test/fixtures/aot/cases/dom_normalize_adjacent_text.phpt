--TEST--
AOT: DOMNode::normalize() merges adjacent createTextNode #text stand-ins (#33438, re-#20642, ext/dom/node.c)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$r = $d->createElement('r');
$d->appendChild($r);
$r->appendChild($d->createTextNode('a'));
$r->appendChild($d->createTextNode('b'));
echo 'before=', $r->childNodes->length, "\n";
$r->normalize();
echo 'after=', $r->childNodes->length, "\n";
echo 'text=', $r->textContent, "\n";
--EXPECT--
before=2
after=1
text=ab
