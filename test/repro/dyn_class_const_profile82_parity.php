<?php
// Issue #24823 — C::{$name} is PHP 8.3+; Zend 8.2 / PROFILE=8.2 must parse-error.
class C { public const X = 7; }
$n = 'X';
echo C::{$n}, "\n";
