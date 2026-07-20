<?php
// VM: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20254_info_null_forward84.php
// AOT: PHP_COMPILER_PROFILE=8.4 php bin/compile.php -o /tmp/info_null84 test/repro/issue_20254_info_null_forward84.php && /tmp/info_null84
// version_compare soft-null under 8.4 (#21556); get_extension_funcs stays TypeError.
foreach ([
    'extension_loaded' => static fn () => extension_loaded(null),
    'get_extension_funcs' => static fn () => get_extension_funcs(null),
    'version_compare' => static fn () => version_compare(null, '8.0'),
    'set_include_path' => static fn () => set_include_path(null),
] as $name => $fn) {
    try {
        $r = $fn();
        echo $name, ' COERCED ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), "\n";
    }
}
