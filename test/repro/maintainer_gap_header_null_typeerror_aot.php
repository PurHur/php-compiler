<?php
/**
 * #21234 — AOT soft-null: header(null) under PHP_COMPILER_PROFILE=8.4.
 *
 * PHP_COMPILER_PROFILE=8.4 php bin/compile.php -o /tmp/hnull test/repro/maintainer_gap_header_null_typeerror_aot.php
 * REQUEST_METHOD=GET /tmp/hnull ; echo exit:$?   # expect 0 + CGI header + OK
 */
$h = null;
header($h);
header('Content-Type: text/plain');
echo "OK\n";
