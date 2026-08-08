--TEST--
stdlib filter_var() FILTER_VALIDATE_URL rejects raw non-ASCII JIT (#29015)
--FILE--
<?php
declare(strict_types=1);
foreach ([
    'http://example.com/café',
    'http://example.com/%c3%a9',
] as $u) {
    echo $u, '=', var_export(filter_var($u, FILTER_VALIDATE_URL), true), "\n";
}
--EXPECT--
http://example.com/café=false
http://example.com/%c3%a9='http://example.com/%c3%a9'
