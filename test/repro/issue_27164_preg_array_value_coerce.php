<?php
// Repro #27164 — preg_* array subject values coerce like Zend (ext/pcre/php_pcre.c).
var_export(preg_grep('/^1/', [12, '13', 14.5]));
echo "\n";
var_export(preg_replace('/1/', 'X', [12, '13']));
echo "\n";
var_export(preg_filter('/a/', 'X', ['a', 'b', 'aa', 5]));
echo "\n";
