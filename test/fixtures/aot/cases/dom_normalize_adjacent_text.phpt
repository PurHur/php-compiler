--TEST--
AOT: DOMNode::normalize() is dispatched (no undefined method) (#20642, re-#15484, ext/dom/node.c)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$r = $d->createElement('r');
$d->appendChild($r);
$r->append($d->createElement('c'));
$r->normalize();
echo "ok\n";
--EXPECT--
ok
