<?php
/**
 * sprintf() assigned to a local then unset must free under thin AOT (#36388).
 *
 * php-src: ext/standard/formatted_print.c PHP_FUNCTION(sprintf) — return is a
 * freshly allocated zend_string; ZEND_ASSIGN / zval_ptr_dtor release it.
 */
function sprintf_local_free(int $n): void {
    $u0 = memory_get_usage(false);
    for ($i = 0; $i < $n; $i++) {
        $s = sprintf('val-%d-%s', $i, 'x');
        unset($s);
    }
    $u1 = memory_get_usage(false);
    $delta = $u1 - $u0;
    echo 'sprintf_local delta=', $delta, ($delta <= 256 ? " ok\n" : " LEAK\n");
}
sprintf_local_free((int) ($argv[1] ?? 2000));
