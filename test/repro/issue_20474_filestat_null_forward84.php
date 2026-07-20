<?php
// Repro #20362 / supersedes #20474 — filestat path null soft-coerces under PROFILE=8.4 (Zend DEP+false).
// VM: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20474_filestat_null_forward84.php
set_error_handler(static function (int $n, string $m): bool {
    echo "W:$m", PHP_EOL;

    return true;
});
foreach (['filesize', 'filemtime', 'filetype', 'is_executable'] as $f) {
    try {
        $r = $f(null);
        echo $f, '=', var_export($r, true), PHP_EOL;
    } catch (Throwable $e) {
        echo $f, '=', $e::class, ':', $e->getMessage(), PHP_EOL;
    }
}
