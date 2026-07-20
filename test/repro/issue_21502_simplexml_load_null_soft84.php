<?php
// Guard #21502 — simplexml_load_string/file(null) soft-null DEP+false under PROFILE=8.4
// (reverts #20352 TypeError polarity; php-src ext/simplexml/simplexml.c Z_PARAM_STR/PATH).
// VM: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_21502_simplexml_load_null_soft84.php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    if (E_WARNING === $no) {
        echo "WARN\n";
        return true;
    }
    echo "E{$no}\n";
    return true;
});
foreach (['simplexml_load_string', 'simplexml_load_file'] as $fn) {
    try {
        $r = $fn(null);
        echo $fn, ':', ($r === false ? 'false' : get_debug_type($r)), "\n";
    } catch (Throwable $e) {
        echo $fn, '_err:', get_class($e), "\n";
    }
}
