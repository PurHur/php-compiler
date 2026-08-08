--TEST--
filter_var() FILTER_VALIDATE_EMAIL domain literals (#29045, ext/filter/logical_filters.c)
--FILE--
<?php
declare(strict_types=1);

foreach ([
    'user@[127.0.0.1]',
    'user@[IPv6:2001:db8::1]',
    'user@[::1]',
    'user@[ipv6:fe80::1]',
    'a@[256.0.0.1]',
    'ok@example.com',
] as $e) {
    echo $e, '=', var_export(filter_var($e, FILTER_VALIDATE_EMAIL), true), "\n";
}
--EXPECT--
user@[127.0.0.1]='user@[127.0.0.1]'
user@[IPv6:2001:db8::1]='user@[IPv6:2001:db8::1]'
user@[::1]=false
user@[ipv6:fe80::1]='user@[ipv6:fe80::1]'
a@[256.0.0.1]=false
ok@example.com='ok@example.com'
