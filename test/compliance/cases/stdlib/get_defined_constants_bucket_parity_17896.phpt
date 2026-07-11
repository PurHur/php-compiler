--TEST--
get_defined_constants(true) standard/date/json bucket parity vs Zend 8.2 reference (#17896, basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

$c = get_defined_constants(true);
echo 'Core: ', isset($c['Core']) ? (string) count($c['Core']) : 'missing', "\n";
echo 'standard: ', isset($c['standard']) ? (string) count($c['standard']) : 'missing', "\n";
echo 'date: ', isset($c['date']) ? (string) count($c['date']) : 'missing', "\n";
echo 'json: ', isset($c['json']) ? (string) count($c['json']) : 'missing', "\n";
echo array_key_exists('user', $c) ? "user_bad\n" : "user_ok\n";
echo isset($c['standard']['INF'], $c['standard']['NAN']) ? "float_ok\n" : "float_bad\n";
echo isset($c['standard']['PASSWORD_ARGON2_PROVIDER']) ? "password_ok\n" : "password_bad\n";
echo isset($c['date']['DATE_RSS'], $c['date']['SUNFUNCS_RET_STRING']) ? "date_ok\n" : "date_bad\n";
echo isset($c['json']['JSON_ERROR_UTF16']) ? "json_ok\n" : "json_bad\n";
foreach ($c as $k => $v) {
    // Must not fatal (issue #4840 Unknown index type 7).
}
echo "foreach_ok\n";
--EXPECT--
Core: 84
standard: 398
date: 17
json: 29
user_ok
float_ok
password_ok
date_ok
json_ok
foreach_ok
