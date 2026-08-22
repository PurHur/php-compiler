<?php
declare(strict_types=1);

// #33881 — variable null must mean document-wide saveXML (Z_PARAM_OBJ_OF_CLASS_OR_NULL).
$doc = new DOMDocument();
$doc->loadXML('<r><a/></r>');
$n = null;
echo $doc->saveXML($n);
echo "---\n";
echo $doc->saveXML(null);
