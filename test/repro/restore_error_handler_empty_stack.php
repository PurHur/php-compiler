<?php

declare(strict_types=1);

// Repro for #12518 — empty-stack restore_error_handler() must return false (php-src).
$r = restore_error_handler();
echo false === $r ? "false\n" : "true\n";
