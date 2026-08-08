<?php
declare(strict_types=1);

// #29064 — FILTER_DEFAULT / UNSAFE_RAW STRIP_* + ENCODE_*
echo 'LOW:', var_export(filter_var("a\0b", FILTER_DEFAULT, FILTER_FLAG_STRIP_LOW), true), "\n";
echo 'HIGH:', var_export(filter_var("a\x7fb", FILTER_DEFAULT, FILTER_FLAG_STRIP_HIGH), true), "\n";
echo 'BTICK:', var_export(filter_var('a`b', FILTER_DEFAULT, FILTER_FLAG_STRIP_BACKTICK), true), "\n";
echo 'ELOW:', var_export(filter_var("a\0b", FILTER_DEFAULT, FILTER_FLAG_ENCODE_LOW), true), "\n";
echo 'EAMP:', var_export(filter_var('a&b', FILTER_DEFAULT, FILTER_FLAG_ENCODE_AMP), true), "\n";
echo 'EHIGH:', var_export(filter_var("a\x80b", FILTER_DEFAULT, FILTER_FLAG_ENCODE_HIGH), true), "\n";
echo 'SAN_HIGH:', bin2hex(filter_var("a\x7f\x80b", FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_HIGH)), "\n";
