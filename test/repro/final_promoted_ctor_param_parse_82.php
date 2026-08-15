<?php
/**
 * #31153 — final on a promoted ctor param is a Zend 8.2 Parse error, not the 8.4 compile fatal.
 *
 *   PHP_COMPILER_PROFILE=8.2 php bin/vm.php test/repro/final_promoted_ctor_param_parse_82.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/final_promoted_ctor_param_parse_82.php
 *   PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/final_promoted_ctor_param_parse_82.php
 */
class C { public function __construct(final public int $x) {} }
echo (new C(1))->x, "\n";
