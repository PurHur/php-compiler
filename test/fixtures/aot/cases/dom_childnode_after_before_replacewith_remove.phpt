--TEST--
AOT: DOM ChildNode after/before/replaceWith/remove (#26752, #29644; empty → <tag/> per libxml/#29409)
--FILE--
<?php
declare(strict_types=1);

$d = new DOMDocument();
$d->loadXML('<root><a/></root>');
$a = $d->getElementsByTagName('a')->item(0);
$a->after($d->createElement('z'));
echo 'after=', trim($d->saveXML($d->documentElement)), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<root><a/></root>');
$a2 = $d2->documentElement->firstChild;
$a2->before($d2->createElement('z'));
echo 'before=', trim($d2->saveXML($d2->documentElement)), "\n";

$d3 = new DOMDocument();
$d3->loadXML('<root><a/></root>');
$a3 = $d3->getElementsByTagName('a')->item(0);
$a3->replaceWith($d3->createElement('z'));
echo 'replaceWith=', trim($d3->saveXML($d3->documentElement)), "\n";

$d4 = new DOMDocument();
$d4->loadXML('<root><a/></root>');
$a4 = $d4->documentElement->firstChild;
$a4->remove();
echo 'remove=', trim($d4->saveXML($d4->documentElement)), "\n";
--EXPECT--
after=<root><a/><z/></root>
before=<root><z/><a/></root>
replaceWith=<root><z/></root>
remove=<root/>

