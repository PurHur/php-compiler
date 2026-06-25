<?php
// Issue #11701 — tempnam('', $prefix) must not emit E_NOTICE (Zend silent fallback).
error_reporting(E_ALL);
ini_set('display_errors', '1');
$path = tempnam('', 'phpc_gap_');
echo is_string($path) ? "ok\n" : "fail\n";
