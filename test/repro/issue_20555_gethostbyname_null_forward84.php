<?php
// VM: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20555_gethostbyname_null_forward84.php
foreach (['gethostbyname' => fn () => gethostbyname(null), 'gethostbynamel' => fn () => gethostbynamel(null)] as $n => $f) {
    try {
        $r = $f();
        echo $n, ' COERCED ', var_export($r, true), PHP_EOL;
    } catch (Throwable $e) {
        echo $n, ' ', get_class($e), PHP_EOL;
    }
}
