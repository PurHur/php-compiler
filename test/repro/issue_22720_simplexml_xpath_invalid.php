<?php

declare(strict_types=1);

// Issue #22720 — SimpleXMLElement::xpath() invalid expression → warning + false.

error_reporting(E_ALL);
$x = simplexml_load_string('<r><a/></r>');
$r = $x->xpath('!!!');
var_export($r);
echo "\n", gettype($r), "\n";
$empty = $x->xpath('/r/missing');
var_export($empty);
echo "\n", gettype($empty), "\n";
