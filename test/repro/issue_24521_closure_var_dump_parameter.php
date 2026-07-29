<?php
declare(strict_types=1);
// Repro #24521 — Closure var_dump parameter bag (Zend/zend_closures.c).
$f = function (int $x): int { return $x; };
var_dump($f);
$g = function (int $x = 1, string $y = 'a') { return $x; };
var_dump($g);
$h = function () { return 1; };
var_dump($h);
