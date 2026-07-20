<?php

/**
 * #21234 — AOT soft-null path for header/printf under PROFILE=8.4.
 *
 * preg_quote AOT is currently broken on master independent of this change
 * (segfault even for preg_quote('a')); VM covers preg_quote soft-null.
 *
 * Follow soft-null header() with a real header so CGI GET flush is well-defined
 * (empty-only pending header after DEP can abort under REQUEST_METHOD=GET).
 *
 * PHP_COMPILER_PROFILE=8.4 php bin/compile.php -o /tmp/i21234 test/repro/issue_21234_header_preg_quote_printf_null_forward84_aot.php
 * REQUEST_METHOD=GET /tmp/i21234
 */
$h = null;
$f = null;
header($h);
header('Content-Type: text/plain');
$n = printf($f);
if (0 !== $n) {
    echo "BAD\n";
    exit(1);
}
echo "OK\n";
