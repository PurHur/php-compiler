<?php
// DOMXPath parent/ancestor/sibling axes + abbreviated .. / child:: (#31773)
// AOT-safe: length + item(0)->nodeName (NodeList foreach aborts in user-script AOT).
$d = new DOMDocument();
$d->loadXML('<r><a id="1">one</a><a id="2">two</a><b>three</b></r>');
$xp = new DOMXPath($d);
echo 'following=', $xp->query('//a[1]/following-sibling::*')->length, "\n";
echo 'following_b=', $xp->query('//a[1]/following-sibling::b')->length, "\n";
echo 'preceding=', $xp->query('//b/preceding-sibling::*')->length, "\n";
echo 'ancestor=', $xp->query('//b/ancestor::*')->item(0)->nodeName, "\n";
echo 'parent=', $xp->query('//a[1]/parent::*')->item(0)->nodeName, "\n";
echo 'self=', $xp->query('//a[1]/self::a')->item(0)->nodeName, "\n";
echo 'child_axis=', $xp->query('/r/child::*')->length, "\n";
echo 'dotdot=', $xp->query('//a[1]/..')->item(0)->nodeName, "\n";
echo 'following_all=', $xp->query('//a[1]/following::*')->length, "\n";
echo 'parent_star=', $xp->query('parent::*')->length, "\n";
