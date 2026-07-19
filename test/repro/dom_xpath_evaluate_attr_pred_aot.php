<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<r><a id="1">x</a><a id="2">y</a><a id="3">z</a></r>');
$xp = new DOMXPath($doc);
echo $xp->query('//a[@id="2"]')->length, "\n";
echo $xp->evaluate('string(//a[@id="2"])'), "\n";
echo $xp->evaluate('string(//a[@id="2"]/@id)'), "\n";
echo (int) $xp->evaluate('count(//a[@id="2"])'), "\n";
echo (int) $xp->evaluate('number(//a[@id="2"]/@id)'), "\n";
