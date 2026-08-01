<?php
/**
 * Repro #26307 — #[\Deprecated] TARGET_CLASS under PROFILE=8.5 is traits-only.
 *
 * php-src Zend/zend_attributes.stub.php adds Attribute::TARGET_CLASS on 8.5+ so traits
 * (and other class-likes) pass the Attribute target mask; validate_deprecated then
 * rejects non-traits (class / interface / enum). Zend 8.5:
 *   Fatal error: Cannot apply #[\Deprecated] to class OldC
 *
 * Run: PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/issue_26307_deprecated_class_reject.php
 */
#[\Deprecated('old')]
class OldC {}
new OldC();
echo "unreachable\n";
