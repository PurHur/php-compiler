<?php
/**
 * Repro #21556 — version_compare(null,…) soft-null under PROFILE=8.4
 * (not TypeError; Zend 8.4.23 php-src-strict).
 *
 * VM:  PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_21556_version_compare_null_forward84.php
 * JIT: PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/issue_21556_version_compare_null_forward84.php
 *
 * AOT: version_compare native link still aborts at runtime after StringVersionCompare
 * insert-block restore (pre-existing helper/bridge; soft-null shares JIT lowering).
 */
error_reporting(E_ALL);
$seen = 0;
set_error_handler(static function (int $no) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen++;
        return true;
    }
    return false;
});
foreach ([
    'v1' => static fn () => version_compare(null, '1'),
    'v2' => static fn () => version_compare('1', null),
] as $n => $fn) {
    try {
        echo $n, ' ', var_export($fn(), true), "\n";
    } catch (TypeError $e) {
        echo $n, " TE\n";
    }
}
echo 'depr=', (int) ($seen >= 2), "\n";
