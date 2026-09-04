<?php
// repro: SimpleXML (array) cast + Phar::running via VmRuntimeSupport (#36204)
$xml = simplexml_load_string('<root a="1"><child>x</child></root>');
$arr = (array) $xml;
echo isset($arr['@attributes']['a']) ? 'attr-ok' : 'attr-fail';
echo ' ';
echo isset($arr['child']) ? 'child-ok' : 'child-fail';
echo ' ';
echo var_export(Phar::running(), true);
echo PHP_EOL;
