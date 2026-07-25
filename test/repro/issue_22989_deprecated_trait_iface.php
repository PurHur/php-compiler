<?php
/**
 * Repro #22989 — #[\Deprecated] trait use (PHP 8.5+) / silent on 8.4.
 *
 * Usage:
 *   PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/issue_22989_deprecated_trait_iface.php
 */
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $msg): bool {
    echo sprintf("[%d] %s\n", $errno, $msg);
    return true;
});

if (true) {
    #[\Deprecated('old trait')]
    trait Tr {}
    class C { use Tr; }
}
echo "done\n";
