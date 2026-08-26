<?php
// #34908 — DOMDocument option prop writes must stick (not hardcoded MetaProps defaults).
$d = new DOMDocument();
echo (int) $d->formatOutput, ' ', (int) $d->preserveWhiteSpace, ' ', (int) $d->strictErrorChecking, "\n";
$d->formatOutput = true;
$d->preserveWhiteSpace = false;
$d->strictErrorChecking = false;
$d->resolveExternals = true;
$d->substituteEntities = true;
$d->validateOnParse = true;
$d->recover = true;
echo (int) $d->formatOutput, ' ', (int) $d->preserveWhiteSpace, ' ', (int) $d->strictErrorChecking, "\n";
echo (int) $d->resolveExternals, ' ', (int) $d->substituteEntities, ' ', (int) $d->validateOnParse, ' ', (int) $d->recover, "\n";

// Defaults after loadXML must still match Zend (#34899).
$d2 = new DOMDocument();
$d2->loadXML('<r/>');
var_export($d2->strictErrorChecking);
echo "\n";
var_export($d2->formatOutput);
echo "\n";
var_export($d2->preserveWhiteSpace);
echo "\n";
