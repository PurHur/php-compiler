<?php
/**
 * #19652 — define()/defined()/constant(null) must TypeError on PHP_COMPILER_PROFILE=8.4
 * (php-src ext/standard/basic_functions.c Z_PARAM_STR).
 *
 * PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_19652_define_null_typeerror.php
 */
$ok = true;
foreach (['define', 'defined', 'constant'] as $fn) {
    try {
        if ('define' === $fn) {
            define(null, 1);
        } elseif ('defined' === $fn) {
            defined(null);
        } else {
            constant(null);
        }
        fwrite(STDERR, "$fn: coerced (expected TypeError)\n");
        $ok = false;
    } catch (TypeError $e) {
        echo $fn, ": TypeError\n";
    }
}
exit($ok ? 0 : 1);
