<?php
declare(strict_types=1);

/**
 * Repro #25359 — memcmp()/modf() must not be userland (php-src stubs omit them).
 */
$ok = true;
foreach (['memcmp', 'modf'] as $f) {
    if (function_exists($f)) {
        fwrite(STDERR, "fail: function_exists('$f') true\n");
        $ok = false;
    }
}
foreach (['fmod', 'strncmp'] as $f) {
    if (!function_exists($f)) {
        fwrite(STDERR, "fail: function_exists('$f') false\n");
        $ok = false;
    }
}
echo $ok ? "ok\n" : "fail\n";
exit($ok ? 0 : 1);
