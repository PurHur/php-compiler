<?php

declare(strict_types=1);

/**
 * Issue #24883 — `new Class()->method()` must parse-reject on default / 8.2 profile.
 *
 * Run (expect non-zero):
 *   php bin/vm.php test/repro/issue_24883_new_without_parens_default_profile.php
 * Forward profile (expect "2020"):
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_24883_new_without_parens_default_profile.php
 *
 * Zend reference: Zend/zend_language_parser.y (RFC new_without_parentheses, PHP 8.4+)
 */

echo new DateTimeImmutable('2020-01-01')->format('Y'), PHP_EOL;
