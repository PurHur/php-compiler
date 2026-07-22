<?php
// #22008 — DOMXPath text()/contains(text(),) predicates (php-src-strict)
$d = new DOMDocument();
$d->loadXML('<r><a>hello</a></r>');
$xp = new DOMXPath($d);
echo 'text=', $xp->query("//a[text()='hello']")->length, "\n";
echo 'contains=', $xp->query("//a[contains(text(),'ell')]")->length, "\n";
echo 'plain=', $xp->query('//a')->length, "\n";
