<?php
// Zend convert_to_string(null) → ''; VM returns NULL from evaluate().
function xp_null() { return null; }
$d = new DOMDocument();
$d->loadXML('<?xml version="1.0"?><r/>');
$x = new DOMXPath($d);
$x->registerNamespace('php', 'http://php.net/xpath');
$x->registerPhpFunctions('xp_null');
$r = $x->evaluate('php:function("xp_null")');
echo get_debug_type($r), ':', var_export($r, true), "\n";
$r2 = $x->evaluate('php:functionString("xp_null")');
echo get_debug_type($r2), ':', var_export($r2, true), "\n";
