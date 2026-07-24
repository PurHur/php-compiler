<?php

declare(strict_types=1);

// Issue #22720 — AOT user-script path: ctor + literal xpath (#19306 / #22720).
// Avoid var_export(false) — known AOT value-box segfault unrelated to xpath.

error_reporting(E_ALL);
$x = new SimpleXMLElement('<r><a/></r>');
$r = $x->xpath('!!!');
echo ($r === false) ? "false\n" : "not-false\n";
echo gettype($r), "\n";
$empty = $x->xpath('/r/missing');
echo is_array($empty) ? ('array:'.count($empty)."\n") : ('type:'.gettype($empty)."\n");
