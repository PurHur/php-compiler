--TEST--
stdlib filter_var() FILTER_VALIDATE_IP NO_RES_RANGE rejects 2001:db8::/32 (#29009, ext/filter/logical_filters.c)
--FILE--
<?php
declare(strict_types=1);
$flag = FILTER_FLAG_NO_RES_RANGE;
foreach (['2001:db8::1', '2001:db8:1::', 'fe80::1', '::1', '2001:4860:4860::8888'] as $ip) {
    echo $ip, ' => ', var_export(filter_var($ip, FILTER_VALIDATE_IP, $flag), true), "\n";
}
echo 'noflag => ', var_export(filter_var('2001:db8::1', FILTER_VALIDATE_IP), true), "\n";
--EXPECT--
2001:db8::1 => false
2001:db8:1:: => false
fe80::1 => false
::1 => false
2001:4860:4860::8888 => '2001:4860:4860::8888'
noflag => '2001:db8::1'
