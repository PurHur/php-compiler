<?php

declare(strict_types=1);

/** Issue #5269 — null array keys coerce to empty string (zend_hash.c). */
$a = [null => 1];
echo json_encode($a), "\n";
echo array_key_exists('', $a) ? "exists\n" : "missing\n";

$b = [];
$b[null] = 2;
echo array_key_exists('', $b) ? "assign_ok\n" : "assign_bad\n";
