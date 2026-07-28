<?php
// #24400 — parse_url() must retain empty query/fragment when ?/# present (php-src url.c)
foreach (['http://x.com?', 'http://x.com#'] as $u) {
    echo $u, ' => ';
    var_export(parse_url($u));
    echo "\n";
}
var_export(parse_url('http://x.com?', PHP_URL_QUERY));
echo "\n";
var_export(parse_url('http://x.com#', PHP_URL_FRAGMENT));
echo "\n";
