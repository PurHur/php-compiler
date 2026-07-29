<?php

declare(strict_types=1);

/**
 * Issue #24895 — final plain properties must compile-fatal on default / 8.2 profile.
 *
 * Run (expect non-zero, Zend-shaped Fatal error):
 *   php bin/vm.php test/repro/issue_24895_final_plain_default_profile.php
 * Forward profile (expect "parsed"):
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_24895_final_plain_default_profile.php
 *
 * Zend reference: Zend/zend_compile.c — final modifier on plain properties (PHP 8.4+)
 */

class C {
    public final int $x = 1;
}
echo "parsed\n";
