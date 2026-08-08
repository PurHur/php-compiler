--TEST--
stdlib filter_var() FILTER_VALIDATE_URL rejects literal space JIT (#28996)
--FILE--
<?php
declare(strict_types=1);
foreach ([
    'http://example.com/a b',
    'http://example.com/a%20b',
    'https://example.com/path',
] as $u) {
    echo json_encode($u), '=', var_export(filter_var($u, FILTER_VALIDATE_URL), true), "\n";
}
--EXPECT--
"http:\/\/example.com\/a b"=false
"http:\/\/example.com\/a%20b"='http://example.com/a%20b'
"https:\/\/example.com\/path"='https://example.com/path'
