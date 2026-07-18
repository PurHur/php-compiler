--TEST--
DOMNode::normalize() merges adjacent text (#20642, ext/dom/node.c)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$d->loadXML('<r/>');
$r = $d->documentElement;
$r->append('a', 'b');
echo 'before=', $r->childNodes->length, "\n";
$r->normalize();
echo 'after=', $r->childNodes->length, "\n";
echo 'text=', $r->textContent, "\n";
--EXPECT--
before=2
after=1
text=ab
