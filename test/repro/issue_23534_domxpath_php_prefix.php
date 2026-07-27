<?php
/** Repro #23534 — unbound php: prefix → warning + boolean false (not string "false"). */
error_reporting(E_ALL);
$dom = new DOMDocument();
$dom->loadXML('<r><a>hello</a></r>');
$xp = new DOMXPath($dom);
$xp->registerPhpFunctions();
$n = $xp->evaluate('string(php:function("strtoupper", string(/r/a)))');
var_export($n);
echo "\n", gettype($n), "\n";
