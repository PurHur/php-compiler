<?php

declare(strict_types=1);

/**
 * Maintainer repro for CLI $argc/$argv parity (issue #4374).
 */

var_dump($argc, $argv);
var_dump($_SERVER['argc'] ?? null, $_SERVER['argv'] ?? null);
