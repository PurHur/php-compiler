<?php
/** AOT/JIT smoke #20280 — DOMXPath::evaluate ops (no var_export — LLVM i1 bug). */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><a/><b>hi</b></r>');
$xp = new DOMXPath($doc);
echo ($xp->evaluate('count(//a) > 1') ? 'true' : 'false')."\n";
echo (string) (float) $xp->evaluate('1+1')."\n";
echo (string) (float) $xp->evaluate('count(//a) + 1')."\n";
echo ($xp->evaluate('not(//c)') ? 'true' : 'false')."\n";
echo (string) $xp->evaluate('name(//b)')."\n";
