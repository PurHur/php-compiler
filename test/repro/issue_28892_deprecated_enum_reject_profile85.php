<?php
/**
 * Repro #28892 — #[\Deprecated] on enum rejected under PROFILE=8.5 (Zend validate_deprecated).
 *
 * Run: PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/issue_28892_deprecated_enum_reject_profile85.php
 */
#[\Deprecated('old')]
enum E {}
echo "unreachable\n";
