<?php
// #26099 — Dom\HTMLDocument keeps valueless boolean attrs (WHATWG empty attribute syntax).
error_reporting(E_ALL);
$html = '<div id="d" hidden disabled></div>';
$doc = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
$d = $doc->getElementById('d');
$names = $d->getAttributeNames();
sort($names);
echo implode(',', $names), "\n";
echo 'hidden=', var_export($d->hasAttribute('hidden'), true), "\n";
echo 'disabled=', var_export($d->hasAttribute('disabled'), true), "\n";
echo 'hidden_val=', var_export($d->getAttribute('hidden'), true), "\n";

$doc2 = Dom\HTMLDocument::createFromString('<div id="e" hidden=""></div>', LIBXML_NOERROR);
echo 'hidden2=', var_export($doc2->getElementById('e')->getAttribute('hidden'), true), "\n";

$doc3 = Dom\HTMLDocument::createFromString('<div id="f" hidden="hidden"></div>', LIBXML_NOERROR);
echo 'hidden3=', var_export($doc3->getElementById('f')->getAttribute('hidden'), true), "\n";
