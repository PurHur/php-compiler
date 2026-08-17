--TEST--
AOT: DOMXPath //*[last()] / //*[position()=1] per-parent (#31923)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a id="1">one</a><a id="2">two</a><b>three</b></r>');
$xp = new DOMXPath($d);
echo 'star_last=', $xp->query('//*[last()]')->length, "\n";
echo 'star_last0=', $xp->query('//*[last()]')->item(0)->nodeName, "\n";
echo 'star_pos1=', $xp->query('//*[position()=1]')->length, "\n";
echo 'star_pos10=', $xp->query('//*[position()=1]')->item(0)->nodeName, "\n";
$nested = new DOMDocument();
$nested->loadXML('<r><x><a>1</a><a>2</a></x><a>3</a></r>');
$xp2 = new DOMXPath($nested);
echo 'nested=', $xp2->query('//a[last()]')->length, "\n";
echo 'nested0=', $xp2->query('//a[last()]')->item(0)->textContent, "\n";
--EXPECT--
star_last=2
star_last0=r
star_pos1=2
star_pos10=r
nested=2
nested0=2
