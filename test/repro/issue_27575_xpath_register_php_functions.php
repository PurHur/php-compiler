<?php

/** Repro #27575 — AOT DOMXPath::registerPhpFunctions() + php:function(). */
$xml = new DOMDocument();
$xml->loadXML('<r><a>hi</a></r>');
$xp = new DOMXPath($xml);
$xp->registerPhpFunctions('strtoupper');
$xp->registerNamespace('php', 'http://php.net/xpath');
$r = $xp->evaluate('php:function("strtoupper", string(//a[1]))');
var_export($r);
echo "\n";
