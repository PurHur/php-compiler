<?php

declare(strict_types=1);

/**
 * Issue #24906 — non-abstract method without body must Fatal like Zend.
 *
 * Run: php bin/vm.php test/repro/issue_24906_method_without_body.php
 *
 * Zend reference: Zend/zend_compile.c — Non-abstract method must contain body
 */

class C {
    public function f();
}
echo "PARSED\n";
(new C)->f();
echo "CALLED\n";
