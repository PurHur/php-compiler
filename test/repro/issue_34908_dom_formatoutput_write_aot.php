<?php

// #34908 — AOT DOMDocument option prop write+read must match Zend (ext/dom/document.c).
$d = new DOMDocument();
echo (int) $d->formatOutput, PHP_EOL;
echo (int) $d->preserveWhiteSpace, PHP_EOL;
echo (int) $d->strictErrorChecking, PHP_EOL;
$d->formatOutput = true;
$d->preserveWhiteSpace = false;
$d->strictErrorChecking = false;
echo (int) $d->formatOutput, PHP_EOL;
echo (int) $d->preserveWhiteSpace, PHP_EOL;
echo (int) $d->strictErrorChecking, PHP_EOL;
