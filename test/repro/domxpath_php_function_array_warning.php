<?php
// Zend: array callback return → E_WARNING Array to string conversion, then string 'Array'.
// VM: returns 'Array' silently (no warning).
function xp_arr() { return ['x', 'y']; }
$d = new DOMDocument();
$d->loadXML('<?xml version="1.0"?><r/>');
$x = new DOMXPath($d);
$x->registerNamespace('php', 'http://php.net/xpath');
$x->registerPhpFunctions('xp_arr');
$r = $x->evaluate('php:function("xp_arr")');
echo get_debug_type($r), ':', var_export($r, true), "\n";
