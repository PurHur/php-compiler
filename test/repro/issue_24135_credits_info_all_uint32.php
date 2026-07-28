<?php
declare(strict_types=1);
/**
 * Repro for #24135 — CREDITS_ALL / INFO_ALL === 4294967295 (php-src-strict).
 */
var_export(CREDITS_ALL);
echo "\n";
var_export(INFO_ALL);
echo "\n";
var_export(CREDITS_ALL === 4294967295);
echo "\n";
var_export(CREDITS_ALL === -1);
echo "\n";
