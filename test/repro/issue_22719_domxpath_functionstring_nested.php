<?php
/** Repro #22719 — string(php:functionString(...)) must match Zend. */
$d = new DOMDocument();
$d->loadXML('<r><a>ab</a></r>');
$x = new DOMXPath($d);
$x->registerNamespace('php', 'http://php.net/xpath');
$x->registerPhpFunctions();
var_export($x->evaluate('string(php:functionString("strtoupper", //a))'));
echo "\n";
