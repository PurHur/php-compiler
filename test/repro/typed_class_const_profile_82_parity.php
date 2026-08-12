<?php
// Issue #30512 — typed class constants must parse-error under PHP_COMPILER_PROFILE=8.2 (Zend 8.2).
class T { public const string X = "a"; }
echo T::X, "\n";
