<?php
/** Repro #32920 — AOT: boxed non-empty string JUMPIF must be truthy. */
$n = 'x';
echo ($n ? $n : 'z');
echo "\n";
$c = ($n ? $n : 'z');
echo $c;
echo "\n";
echo ('' ? 't' : 'empty');
echo "\n";
echo ('0' ? 't' : 'zero');
echo "\n";
