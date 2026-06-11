<?php
// Repro for #6174 — vfscanf() stream formatted input.
echo 'vfscanf: ', function_exists('vfscanf') ? 'yes' : 'no', "\n";
$fp = fopen('php://memory', 'r+');
fwrite($fp, '42 hello');
rewind($fp);
$n = 0;
$s = '';
$c = vfscanf($fp, '%d %s', $n, $s);
var_export([$c, $n, $s]);
echo "\n";
