<?php

declare(strict_types=1);

// Repro for #12595 — empty-stack restore_error_handler() must return true (php-src).
$r = restore_error_handler();
echo true === $r ? "true\n" : "false\n";
