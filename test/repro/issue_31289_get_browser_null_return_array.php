<?php
declare(strict_types=1);
// #31289 — get_browser null $return_array under strict_types → TypeError (ext/standard/browscap.c)
// AOT: use non-null $user_agent — literal null UA + 2nd arg hits a pre-existing thin-AOT crash
// outside get_browser::call (same class as uniqid(null, false)). Arg #2 TypeError is what we guard.
try {
    var_export(get_browser('Mozilla/5.0', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
