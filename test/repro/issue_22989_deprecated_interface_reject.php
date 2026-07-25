<?php
/**
 * Repro #22989 — #[\Deprecated] on interface rejects under PHP 8.5+.
 *
 * Usage:
 *   PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/issue_22989_deprecated_interface_reject.php
 */
#[\Deprecated('old iface')]
interface I {}
echo "unreachable\n";
