<?php
// VM: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_21061_mbstring_null_profile84.php
foreach ([
    'mb_strwidth' => static fn () => mb_strwidth(null),
    'mb_strstr' => static fn () => mb_strstr(null, 'a'),
    'mb_convert_case' => static fn () => mb_convert_case(null, MB_CASE_UPPER),
    'mb_scrub' => static fn () => mb_scrub(null),
] as $n => $f) {
    try {
        $r = $f();
        echo "$n=COERCE:", var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo "$n=", get_class($e), "\n";
    }
}
