<?php

declare(strict_types=1);

/**
 * Issue #24755 — PHP 8.4 `new` in expressions without outer parentheses.
 *
 * Run: php bin/vm.php test/repro/issue_24755_new_without_parens.php
 *
 * Zend reference: Zend/zend_language_parser.y (RFC new_without_parentheses)
 */

class Builder {
    public function build(): string { return 'built'; }
}

echo new Builder()->build() . "\n";
