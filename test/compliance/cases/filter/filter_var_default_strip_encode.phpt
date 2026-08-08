--TEST--
filter_var() FILTER_DEFAULT/UNSAFE_RAW STRIP_*/ENCODE_* (#29064, ext/filter/sanitizing_filters.c)
--FILE--
<?php
declare(strict_types=1);

echo 'LOW:', var_export(filter_var("a\0b", FILTER_DEFAULT, FILTER_FLAG_STRIP_LOW), true), "\n";
echo 'HIGH:', var_export(filter_var("a\x7fb", FILTER_DEFAULT, FILTER_FLAG_STRIP_HIGH), true), "\n";
echo 'BTICK:', var_export(filter_var('a`b', FILTER_DEFAULT, FILTER_FLAG_STRIP_BACKTICK), true), "\n";
echo 'ELOW:', var_export(filter_var("a\0b", FILTER_DEFAULT, FILTER_FLAG_ENCODE_LOW), true), "\n";
echo 'EAMP:', var_export(filter_var('a&b', FILTER_DEFAULT, FILTER_FLAG_ENCODE_AMP), true), "\n";
echo 'EHIGH:', var_export(filter_var("a\x80b", FILTER_DEFAULT, FILTER_FLAG_ENCODE_HIGH), true), "\n";
echo 'UNSAFE:', var_export(filter_var("a\0b", FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW), true), "\n";
echo 'SAN_HIGH:', bin2hex(filter_var("a\x7f\x80b", FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_HIGH)), "\n";
--EXPECT--
LOW:'ab'
HIGH:'ab'
BTICK:'ab'
ELOW:'a&#0;b'
EAMP:'a&#38;b'
EHIGH:'a&#128;b'
UNSAFE:'ab'
SAN_HIGH:6162
