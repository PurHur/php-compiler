<?php
/** Repro #31312 — mb_strtoupper/tolower null encoding; mb_split null limit. */
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo mb_strtoupper('ab', null), "\n";
echo mb_strtolower('AB', null), "\n";
$parts = mb_split('a', 'abc', null);
echo is_array($parts) ? implode('|', $parts) : 'FAIL', "\n";
