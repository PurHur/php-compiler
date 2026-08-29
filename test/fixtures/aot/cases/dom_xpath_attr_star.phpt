--TEST--
AOT: DOMXPath //@* / attribute::* lengths (#32003)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a id="x" class="c">1</a><b y="2"/></r>');
$xp = new DOMXPath($d);
echo 'star=', $xp->query('//@*')->length, "\n";
echo 'star0=', $xp->query('//@*')->item(0)->nodeName, "\n";
echo 'star1=', $xp->query('//@*')->item(1)->nodeName, "\n";
echo 'star2=', $xp->query('//@*')->item(2)->nodeName, "\n";
echo 'a_star=', $xp->query('//a/@*')->length, "\n";
echo 'axis=', $xp->query('//attribute::*')->length, "\n";
echo 'named=', $xp->query('//@id')->length, "\n";
--EXPECT--
star=3
star0=id
star1=class
star2=y
a_star=2
axis=3
named=1
