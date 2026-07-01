<?php
declare(strict_types=1);

$doc = new DOMDocument();
$doc->validateOnParse = true;
$doc->loadXML('<!DOCTYPE root [<!ATTLIST child id ID #IMPLIED>]><root><child id="target">x</child></root>');
$found = $doc->getElementById('target');
echo null !== $found ? "dtd_ok\n" : "dtd_null\n";
