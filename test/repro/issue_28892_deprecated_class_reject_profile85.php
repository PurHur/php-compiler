<?php
/**
 * Repro #28892 (re-#28818 / #26307) — #[\Deprecated] TARGET_CLASS under PROFILE=8.5 is traits-only.
 *
 * php-src Zend/zend_attributes.stub.php adds Attribute::TARGET_CLASS on 8.5+ so traits
 * pass the Attribute target mask; validate_deprecated then rejects non-traits
 * (class / interface / enum). Verified Zend 8.5-cli:
 *   Fatal error: Cannot apply #[\Deprecated] to class OldC
 *
 * Do not "fix" by accepting Deprecated on classes — that regresses php-src-strict.
 *
 * Run: PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/issue_28892_deprecated_class_reject_profile85.php
 */
#[\Deprecated('old')]
class OldC {}
new OldC();
echo "unreachable\n";
