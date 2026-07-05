<?php

declare(strict_types=1);

/**
 * Issue #16565 — AOT compile ++/-- on null + var_dump must not SIGSEGV the compiler.
 */
$x = null;
echo ++$x, '|';
$y = null;
$y--;
var_dump($y);
