<?php
/** Repro #21666 — SimpleXMLElement (array) / get_object_vars vs Zend. */
$sx = simplexml_load_string('<r a="1"><c>x</c></r>');
echo 'cast=';
var_export((array) $sx);
echo "\n gov=";
var_export(get_object_vars($sx));
echo "\n attr=";
var_export((array) $sx->attributes());
echo "\n";
$ch = simplexml_load_string('<r><c>x</c><d>y</d></r>')->children();
echo 'children=';
var_export((array) $ch);
echo "\n";
