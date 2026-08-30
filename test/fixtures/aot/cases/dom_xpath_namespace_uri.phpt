--TEST--
AOT: DOMXPath::evaluate() namespace-uri() on elements (#21238, ext/dom/xpath.c)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$d->loadXML('<r xmlns:x="urn:x" xmlns="urn:def"><x:a/><c/></r>');
$xp = new DOMXPath($d);
$xp->registerNamespace('x', 'urn:x');
$xp->registerNamespace('d', 'urn:def');
echo $xp->evaluate('namespace-uri(//x:a)'), "\n";
echo $xp->evaluate('namespace-uri(/*)'), "\n";
echo $xp->evaluate('namespace-uri(//d:c)'), "\n";
echo $xp->evaluate('local-name(//x:a)'), "\n";
echo $xp->evaluate('name(//x:a)'), "\n";
--EXPECT--
urn:x
urn:def
urn:def
a
x:a
