<?php
// Repro #31923 — DOMXPath //*[last()] per-parent child axis (xpath.c)
// AOT-safe: length + item(0) (NodeList foreach / item(1) abort in user-script AOT).
$d = new DOMDocument();
$d->loadXML('<r><a id="1">one</a><a id="2">two</a><b>three</b></r>');
$xp = new DOMXPath($d);
$last = $xp->query('//*[last()]');
echo 'star_last=', $last->length, ' ', $last->item(0)->nodeName, "\n";
$first = $xp->query('//*[position()=1]');
echo 'star_pos1=', $first->length, ' ', $first->item(0)->nodeName, "\n";
$nested = new DOMDocument();
$nested->loadXML('<r><x><a>1</a><a>2</a></x><a>3</a></r>');
$xp2 = new DOMXPath($nested);
$nl = $xp2->query('//a[last()]');
echo 'nested_a_last=', $nl->length, ' ', $nl->item(0)->textContent, "\n";
