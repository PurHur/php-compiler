--TEST--
ext/dom DOMXPath::registerPhpFunctions() — php:function() callbacks (#19331, ext/dom/xpath.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r><a>hi</a></r>');
$xp = new DOMXPath($doc);
echo 'has=', method_exists($xp, 'registerPhpFunctions') ? '1' : '0', "\n";
$xp->registerNamespace('php', 'http://php.net/xpath');
$xp->registerPhpFunctions('strtoupper');
var_export($xp->evaluate('php:function("strtoupper", string(//a))'));
echo "\n";
?>
--EXPECT--
has=1
'HI'
