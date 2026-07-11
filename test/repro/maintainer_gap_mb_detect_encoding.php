<?php

declare(strict_types=1);

/**
 * Issue #3075 — mb_detect_encoding() parity with Zend.
 */
$iso = "\xE9";
echo mb_detect_encoding($iso, ['ISO-8859-1', 'UTF-8'], true), "\n";
echo mb_detect_encoding($iso, ['UTF-8', 'ISO-8859-1'], true), "\n";
echo function_exists('mb_detect_encoding') ? "ok\n" : "missing\n";
