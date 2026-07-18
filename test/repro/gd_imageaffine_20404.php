<?php
/**
 * Repro for #20404 — imageaffine() identity matrix.
 */
var_export(function_exists('imageaffine'));
echo PHP_EOL;
$im = imagecreatetruecolor(4, 4);
$out = imageaffine($im, [1, 0, 0, 1, 0, 0]);
echo get_debug_type($out), PHP_EOL;
echo imagesx($out), 'x', imagesy($out), PHP_EOL;
