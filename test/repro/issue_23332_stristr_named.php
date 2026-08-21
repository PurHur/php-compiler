<?php
// #23332 — Reflection names + named before_needle; AOT must compile (scan ABI scope).
$r = new ReflectionFunction('stristr');
echo 'names=', implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
var_export(stristr(haystack: 'AbCd', needle: 'bc', before_needle: true));
echo "\n";
var_export(stristr('AbCd', 'bc', true));
echo "\n";
