<?php
/**
 * Maintainer gap — AOT get_headers() compile fails (ext/standard/head.c).
 *
 * Zend/VM/JIT: false for refused connection (with @).
 * AOT: compile aborts — "Current basic block has no parent function".
 *
 * php-src: ext/standard/head.c — PHP_FUNCTION(get_headers)
 *
 * Run:
 *   php bin/vm.php test/repro/maintainer_gap_aot_get_headers.php
 *   php bin/jit.php test/repro/maintainer_gap_aot_get_headers.php
 *   php bin/compile.php test/repro/maintainer_gap_aot_get_headers.php -o /tmp/get_headers_aot
 */
echo var_export(@get_headers('http://127.0.0.1:1/') === false, true), "\n";
