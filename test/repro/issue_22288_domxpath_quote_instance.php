<?php
/** Repro #22288 — DOMXPath::quote() via instance under PROFILE=8.4. */
$d = new DOMDocument();
$d->loadXML('<r/>');
$xp = new DOMXPath($d);
echo method_exists($xp, 'quote') ? 'Y' : 'N', PHP_EOL;
echo $xp->quote('abc'), PHP_EOL;
echo DOMXPath::quote('abc'), PHP_EOL;
