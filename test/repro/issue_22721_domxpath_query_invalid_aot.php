<?php

declare(strict_types=1);

// Issue #22721 — AOT user-script: invalid query → warning + false.
// Avoid var_export(false) — known AOT value-box segfault unrelated to xpath.

error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r/>');
$x = new DOMXPath($d);
$r = $x->query('!!!');
echo ($r === false) ? "false\n" : "not-false\n";
echo gettype($r), "\n";
