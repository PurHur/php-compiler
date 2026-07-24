<?php

declare(strict_types=1);

// Issue #22755 — AOT user-script: invalid evaluate → warning + false.
// Avoid var_export(false) — known AOT value-box segfault unrelated to xpath.

error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r><a>1</a></r>');
$x = new DOMXPath($d);
$r = $x->evaluate('@@@');
echo ($r === false) ? "false\n" : "not-false\n";
echo gettype($r), "\n";
