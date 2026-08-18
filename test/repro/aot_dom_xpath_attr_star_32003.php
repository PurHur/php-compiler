<?php
// AOT follow-up #32003 — DOMXPath //@* item(0)->nodeName
$d = new DOMDocument();
$d->loadXML('<r><a id="x" class="c">1</a><b y="2"/></r>');
$xp = new DOMXPath($d);
echo 'star=', $xp->query('//@*')->length, "\n";
echo 'star0=', $xp->query('//@*')->item(0)->nodeName, "\n";
echo 'a_star=', $xp->query('//a/@*')->length, "\n";
echo 'axis=', $xp->query('//attribute::*')->length, "\n";
echo 'named=', $xp->query('//@id')->length, "\n";
