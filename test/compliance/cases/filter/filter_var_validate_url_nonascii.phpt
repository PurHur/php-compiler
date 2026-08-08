--TEST--
stdlib filter_var() FILTER_VALIDATE_URL rejects raw non-ASCII (#29015, ext/filter/logical_filters.c)
--FILE--
<?php
declare(strict_types=1);
foreach ([
    'http://example.com/café',
    'http://example.com/?q=café',
    'http://example.com/#café',
    'http://example.com/%c3%a9',
    'http://exämple.com/',
] as $u) {
    echo $u, '=', var_export(filter_var($u, FILTER_VALIDATE_URL), true), "\n";
}
--EXPECT--
http://example.com/café=false
http://example.com/?q=café=false
http://example.com/#café=false
http://example.com/%c3%a9='http://example.com/%c3%a9'
http://exämple.com/=false
