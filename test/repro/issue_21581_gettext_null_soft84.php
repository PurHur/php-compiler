<?php
/**
 * Repro #21581 — gettext family null soft-null under PROFILE=8.4
 * (Zend 8.4 DEP+''; #21581 reverts over-strict #20209 TypeError).
 *
 * VM:  PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_21581_gettext_null_soft84.php
 * JIT: PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/issue_21581_gettext_null_soft84.php
 */
error_reporting(E_ALL);
$seenDep = 0;
set_error_handler(static function (int $no, string $msg) use (&$seenDep): bool {
    if (E_DEPRECATED === $no) {
        $seenDep++;
        return true;
    }
    return false;
});
foreach (['gettext', '_'] as $f) {
    try {
        echo $f, ' ', var_export($f(null), true), "\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
try {
    echo 'dgettext ', var_export(dgettext('messages', null), true), "\n";
} catch (Throwable $e) {
    echo 'dgettext ', get_class($e), "\n";
}
try {
    echo 'ngettext ', var_export(ngettext(null, null, 1), true), "\n";
} catch (Throwable $e) {
    echo 'ngettext ', get_class($e), "\n";
}
try {
    bindtextdomain(null, '/tmp');
    echo "bindtextdomain ok\n";
} catch (ValueError $e) {
    echo 'bindtextdomain ValueError empty=', (int) str_contains($e->getMessage(), 'must not be empty'), "\n";
} catch (Throwable $e) {
    echo 'bindtextdomain ', get_class($e), "\n";
}
echo 'depr=', (int) ($seenDep >= 4), "\n";
