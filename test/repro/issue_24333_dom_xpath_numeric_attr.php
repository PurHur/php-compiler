<?php
/**
 * Repro #24333 — DOMXPath [@attr=N] unquoted numeric equality (XPath 1.0 / php-src xpath.c).
 */
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<r><a id="1">x</a><a id="2">y</a><a id="1.0">z</a><a id="01">w</a></r>');
$xp = new DOMXPath($doc);

echo 'eval=', $xp->evaluate('string(//a[@id=1])'), "\n";
echo 'query=', $xp->query('//a[@id=1]')->length, "\n";
echo 'quoted=', $xp->evaluate('string(//a[@id="1"])'), "\n";
echo 'quoted_len=', $xp->query('//a[@id="1"]')->length, "\n";
echo 'bool=', (int) $xp->evaluate('boolean(//a[@id=1])'), "\n";
echo 'count=', (int) $xp->evaluate('count(//a[@id=1])'), "\n";
echo 'attr_axis=', $xp->query('//a[@id=1]/@id')->length, "\n";
