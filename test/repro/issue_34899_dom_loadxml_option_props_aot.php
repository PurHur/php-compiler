<?php

// #34899 — AOT loadXML document option props must match Zend (no SIGSEGV).
$d = new DOMDocument();
$d->loadXML('<r/>');
var_export($d->strictErrorChecking);
echo "\n";
var_export($d->formatOutput);
echo "\n";
var_export($d->preserveWhiteSpace);
echo "\n";
var_export($d->validateOnParse);
echo "\n";
var_export($d->resolveExternals);
echo "\n";
var_export($d->recover);
echo "\n";
var_export($d->substituteEntities);
echo "\n";
