--TEST--
AOT: DOMXPath::registerPhpFunctions() + php:function() (#27575, ext/dom/xpath.c)
--FILE--
<?php
declare(strict_types=1);
$xml = new DOMDocument();
$xml->loadXML('<r><a>hi</a></r>');
$xp = new DOMXPath($xml);
$xp->registerPhpFunctions('strtoupper');
$xp->registerNamespace('php', 'http://php.net/xpath');
$r = $xp->evaluate('php:function("strtoupper", string(//a[1]))');
var_export($r);
echo "\n";
--EXPECT--
'HI'
