<?php

// #34908 — AOT DOMDocument option prop writes must stick (leftover of #34899 MetaProps hardcode).
$d = new DOMDocument();
$d->formatOutput = true;
echo (int) $d->formatOutput, PHP_EOL;
$d->preserveWhiteSpace = false;
echo (int) $d->preserveWhiteSpace, PHP_EOL;
$d->strictErrorChecking = false;
echo (int) $d->strictErrorChecking, PHP_EOL;
$d->validateOnParse = true;
echo (int) $d->validateOnParse, PHP_EOL;
// Defaults still Zend after construct (no write).
$e = new DOMDocument();
echo (int) $e->formatOutput, '|', (int) $e->preserveWhiteSpace, PHP_EOL;
