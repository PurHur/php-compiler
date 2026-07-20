<?php
/**
 * Repro #21492 — getimagesizefromstring(null) soft-null under PROFILE=8.4
 * (DEP + notice + false; not TypeError; Zend 8.4.23 php-src-strict).
 *
 * VM:  PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_21492_getimagesizefromstring_null_forward84.php
 * JIT: PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/issue_21492_getimagesizefromstring_null_forward84.php
 * AOT: PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=1 ./script/docker-exec.sh -- bash -lc \
 *        './phpc build -o /tmp/gis21492 test/repro/issue_21492_getimagesizefromstring_null_forward84_aot.php && /tmp/gis21492'
 *
 * Full DEP+notice handlers are VM/JIT-only here: AOT set_error_handler + this builtin
 * currently segfaults at compile (pre-existing). AOT return-value probe is *_aot.php.
 */
error_reporting(E_ALL);
$seenDep = 0;
$seenNotice = 0;
set_error_handler(static function (int $no, string $msg) use (&$seenDep, &$seenNotice): bool {
    if (E_DEPRECATED === $no) {
        $seenDep++;
        return true;
    }
    if (E_NOTICE === $no || E_WARNING === $no) {
        if (str_contains($msg, 'Error reading from !')) {
            $seenNotice++;
        }
        return true;
    }
    return false;
});
try {
    echo 'result ', var_export(getimagesizefromstring(null), true), "\n";
} catch (TypeError $e) {
    echo "TE\n";
}
echo 'depr=', (int) ($seenDep >= 1), "\n";
echo 'notice=', (int) ($seenNotice >= 1), "\n";
