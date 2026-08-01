<?php

/**
 * Repro #26308 — #[\Deprecated] on global const is 8.5-only (TARGET_CONSTANT).
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_26308_deprecated_global_const_profile.php
 * Expect: Parse error: syntax error, unexpected token "const" (exit 255)
 *
 *   PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/issue_26308_deprecated_global_const_profile.php
 * Expect: E_DEPRECATED on use, then prints 1
 *
 * Class-constant Deprecated remains allowed under 8.4 (separate snippet in issue Done when).
 */

#[\Deprecated('old')]
const OLD_C = 1;
echo OLD_C, "\n";
