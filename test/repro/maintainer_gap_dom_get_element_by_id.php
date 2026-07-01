<?php
declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><child id="target">x</child></root>');
$found = $doc->getElementById('target');
echo null === $found ? "null\n" : "ok\n";
$missing = $doc->getElementById('missing');
echo null === $missing ? "missing_null\n" : "missing_found\n";
