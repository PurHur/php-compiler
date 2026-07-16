--TEST--
AOT: DOMXPath::evaluate() sum() (#19682)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<r><a>3</a><a>4</a></r>');
$xpath = new DOMXPath($doc);
echo (int) $xpath->evaluate('sum(//a)'), "\n";
echo (int) $xpath->evaluate('sum(//missing)'), "\n";
echo (int) $xpath->evaluate('count(//a)'), "\n";
--EXPECT--
7
0
2
