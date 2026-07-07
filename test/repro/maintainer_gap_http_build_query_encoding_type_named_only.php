<?php

declare(strict_types=1);

$out = http_build_query(['a' => 'b c'], encoding_type: PHP_QUERY_RFC3986);
if ('a=b%20c' !== $out) {
    echo 'fail: ', var_export($out, true), "\n";
    exit(1);
}
echo "ok\n";
