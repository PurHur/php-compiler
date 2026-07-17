<?php
// Repro #20071 — DatePeriod option constants visible to defined()/Reflection
var_export(defined('DatePeriod::INCLUDE_END_DATE'));
echo "\n";
var_export(defined('DatePeriod::EXCLUDE_START_DATE'));
echo "\n";
$r = new ReflectionClass(DatePeriod::class);
var_export($r->hasConstant('INCLUDE_END_DATE'));
echo "\n";
var_export($r->hasConstant('EXCLUDE_START_DATE'));
echo "\n";
$consts = $r->getConstants();
ksort($consts);
var_export($consts);
echo "\n";
