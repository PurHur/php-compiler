<?php
/**
 * #28816 — default / PROFILE=8.2 rejects `final` on plain properties (php-src-strict).
 *
 * Zend: final modifier is allowed only for methods, classes, and class constants.
 * Expect: Fatal on declaration (never parsed).
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.2 php bin/vm.php test/repro/issue_28816_final_plain_reject_82.php
 *   php bin/vm.php test/repro/issue_28816_final_plain_reject_82.php
 */
class A { final public int $x = 1; }
echo "parsed\n";
