<?php
// Possessive quantifiers ++ / *+ / ?+ (#36380 — Parsedown table cells).
// @differential-skip-aot: committed helper-runtime __compiler_preg_match returns Internal error on quantified literals (/a+/, /./); VM Pure path is the SSOT for this fix — AOT needs helper refresh (#36380 follow-up)
echo (int) preg_match('/a++/', 'aaa'), "\n";
echo (int) preg_match('/a++a/', 'aaa'), "\n";
echo (int) preg_match('/a*+b/', 'aaab'), "\n";
echo (int) preg_match('/a*+a/', 'aaa'), "\n";
echo (int) preg_match('/a?+a/', 'aa'), "\n";
$m = null;
echo (int) preg_match_all('/(?:(\\\\[|])|[^|`]|`[^`]++`|`)++/', 'a|b|c', $m), "\n";
echo isset($m[0]) ? count($m[0]) : 'missing', "\n";
echo isset($m[0][0]) ? $m[0][0] : 'x', "\n";
$empty = null;
echo (int) preg_match_all('/xyz/', 'abc', $empty), "\n";
echo isset($empty[0]) && is_array($empty[0]) ? 'ok' : 'bad', "\n";
