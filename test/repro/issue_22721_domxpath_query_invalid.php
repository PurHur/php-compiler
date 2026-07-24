<?php

declare(strict_types=1);

// Issue #22721 — DOMXPath::query() invalid expression → warning + false.

error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r/>');
$x = new DOMXPath($d);
$r = $x->query('!!!');
echo ($r === false) ? "false\n" : "not-false\n";
echo gettype($r), "\n";
