<?php
// AOT: DOMDocument validate/schemaValidate/relaxNG/xinclude/registerNodeClass must not be NULL (#35540).
$d = new DOMDocument();
$d->loadXML('<r/>');
echo 'validate=';
var_export($d->validate());
echo "\n";
echo 'schema=';
var_export($d->schemaValidate('/nope.xsd'));
echo "\n";
echo 'schemaSrc=';
var_export($d->schemaValidateSource('<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"/>'));
echo "\n";
echo 'rng=';
var_export($d->relaxNGValidate('/nope.rng'));
echo "\n";
echo 'rngSrc=';
var_export($d->relaxNGValidateSource('<grammar xmlns="http://relaxng.org/ns/structure/1.0"/>'));
echo "\n";
echo 'xinclude=';
var_export($d->xinclude());
echo "\n";
echo 'register=';
var_export($d->registerNodeClass('DOMElement', 'DOMElement'));
echo "\n";
