--TEST--
AOT: DOMXPath parent/ancestor/following-sibling axes (#31773)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a id="1">one</a><a id="2">two</a><b>three</b></r>');
$xp = new DOMXPath($d);
echo 'following=', $xp->query('//a[1]/following-sibling::*')->length, "\n";
echo 'preceding=', $xp->query('//b/preceding-sibling::*')->length, "\n";
echo 'ancestor=', $xp->query('//b/ancestor::*')->item(0)->nodeName, "\n";
echo 'parent=', $xp->query('//a[1]/parent::*')->item(0)->nodeName, "\n";
echo 'child=', $xp->query('/r/child::*')->length, "\n";
echo 'dotdot=', $xp->query('//a[1]/..')->item(0)->nodeName, "\n";
--EXPECT--
following=2
preceding=2
ancestor=r
parent=r
child=3
dotdot=r
