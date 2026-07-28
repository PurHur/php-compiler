--TEST--
AOT: DOMXPath [@attr=N] numeric attribute predicates (#24333)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<r><a id="1">x</a><a id="2">y</a><a id="1.0">z</a><a id="01">w</a></r>');
$xp = new DOMXPath($doc);
echo $xp->query('//a[@id=1]')->length, "\n";
echo $xp->evaluate('string(//a[@id=1])'), "\n";
echo $xp->query('//a[@id="1"]')->length, "\n";
echo (int) $xp->evaluate('boolean(//a[@id=1])'), "\n";
echo (int) $xp->evaluate('count(//a[@id=1])'), "\n";
--EXPECT--
3
x
1
1
3
