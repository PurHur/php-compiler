<?php
/** Repro #22737 — default xmlns: property + children()/children($uri) (re-#19342). */
$xml = simplexml_load_string('<r xmlns="urn:d"><c>1</c></r>');
echo 'direct=', (string) $xml->c, "\n";
echo 'isset=', isset($xml->c) ? '1' : '0', "\n";
$c = $xml->children('urn:d');
echo 'children=', (string) $c->c, "\n";
$c0 = $xml->children();
echo 'children0=', (string) $c0->c, "\n";
echo 'children0_count=', $c0->count(), "\n";
