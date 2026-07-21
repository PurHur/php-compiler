<?php
/** Repro #21666 — SimpleXMLElement (array) / get_object_vars (AOT via construct). */
$sx = new SimpleXMLElement('<r a="1"><c>x</c></r>');
echo 'cast=';
var_export((array) $sx);
echo "\n gov=";
var_export(get_object_vars($sx));
echo "\n";
