<?php
/** Repro #19764 — ArrayObject::__debugInfo / var_dump storage bag. */
error_reporting(E_ALL);
$o = new ArrayObject(['a' => 1]);
ob_start();
var_dump($o);
$out = ob_get_clean();
echo (strpos($out, 'storage') !== false) ? "ok\n" : "fail\n";
