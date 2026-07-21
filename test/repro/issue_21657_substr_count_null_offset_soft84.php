<?php
/**
 * Repro #21657 — substr_count(null $offset) soft-null under PROFILE=8.4
 * (Zend 8.4 DEP+coerce→0 + full count).
 *
 * VM:  PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_21657_substr_count_null_offset_soft84.php
 * JIT: PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/issue_21657_substr_count_null_offset_soft84.php
 */
set_error_handler(static function (int $n, string $m): bool {
    if (E_DEPRECATED === $n) {
        echo "DEP\n";

        return true;
    }

    return false;
});
try {
    echo substr_count('aaa', 'a', null), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
