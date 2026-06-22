<?php
/** Repro for #10534 — unqualified builtin calls in namespace (Zend/zend_compile.c). */
namespace N;

const C = 1;

var_export(C);
echo "\n";
echo count([1, 2, 3]), "\n";
echo strlen('hi'), "\n";
echo \count([1, 2, 3]), "\n";
