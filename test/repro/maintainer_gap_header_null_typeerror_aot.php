<?php
/**
 * #19224 — AOT abort path: header(null) TypeError under PHP_COMPILER_PROFILE=8.4.
 *
 * PHP_COMPILER_PROFILE=8.4 php bin/compile.php -o /tmp/hnull test/repro/maintainer_gap_header_null_typeerror_aot.php
 * /tmp/hnull ; echo exit:$?   # expect 255 + Uncaught TypeError on stderr
 */
header(null);
echo "FAIL: reached after header(null)\n";
