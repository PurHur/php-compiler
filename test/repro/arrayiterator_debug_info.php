<?php
/** Repro #19782 — ArrayIterator::__debugInfo / var_dump storage bag. */
error_reporting(E_ALL);
$i = new ArrayIterator(['x' => 9]);
ob_start();
var_dump($i);
$out = ob_get_clean();
echo (strpos($out, 'storage') !== false) ? "ok\n" : "fail\n";
