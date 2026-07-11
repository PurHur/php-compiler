<?php

$legacy = http_build_query(['a b' => 1], '', PHP_QUERY_RFC3986);
if ('a+b=1' !== $legacy) {
    echo 'fail:legacy:', $legacy, "\n";
    exit(1);
}
echo "ok:legacy\n";

$explicit = http_build_query(['a b' => 1], '', '&', PHP_QUERY_RFC3986);
if ('a%20b=1' !== $explicit) {
    echo 'fail:explicit:', $explicit, "\n";
    exit(1);
}
echo "ok:explicit\n";
