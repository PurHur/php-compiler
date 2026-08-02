--TEST--
AOT: ParentNode::append mixed element+text — saveXML keeps elements (#26765, ext/dom/parentnode.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<root/>');
$r = $d->documentElement;
$r->append($d->createElement('a'), 'txt', $d->createElement('b'));
echo $d->saveXML($r), "\n";
$r2 = (new DOMDocument());
$r2->loadXML('<root/>');
$e = $r2->documentElement;
$e->prepend($r2->createElement('a'), 'txt', $r2->createElement('b'));
echo $r2->saveXML($e), "\n";
--EXPECT--
<root><a/>txt<b/></root>
<root><a/>txt<b/></root>
