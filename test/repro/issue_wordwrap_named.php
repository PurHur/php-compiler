<?php
declare(strict_types=1);

// Issue #9524 — wordwrap() width:/break:/cut: named parameters (ext/standard/string.c).

var_export(wordwrap('hello world', width: 5));
echo "\n";
var_export(wordwrap(string: 'hello world', width: 5, break: "\n"));
echo "\n";
