<?php
// repro: SimpleXML casts/empty + Phar::running via VmRuntimeSupport (#36204)
$xml = simplexml_load_string('<root a="1"><child>x</child></root>');
$arr = (array) $xml;
echo isset($arr['@attributes']['a']) ? 'attr-ok' : 'attr-fail';
echo ' ';
echo isset($arr['child']) ? 'child-ok' : 'child-fail';
echo ' ';
$num = simplexml_load_string('<n>7</n>');
echo ((int) $num === 7) ? 'int-ok' : 'int-fail';
echo ' ';
echo ((bool) simplexml_load_string('<e></e>')) ? 'bool-fail' : 'bool-ok';
echo ' ';
$dim = simplexml_load_string('<r><c></c></r>');
echo empty($dim['c']) ? 'empty-ok' : 'empty-fail';
echo ' ';
echo var_export(Phar::running(), true);
echo PHP_EOL;
