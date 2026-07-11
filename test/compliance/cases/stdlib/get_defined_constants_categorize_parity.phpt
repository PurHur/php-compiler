--TEST--
get_defined_constants(true) module bucket counts match Zend reference profile (#17896, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

$c = get_defined_constants(true);
$standard = isset($c['standard']) ? count($c['standard']) : 0;
$date = isset($c['date']) ? count($c['date']) : 0;
$json = isset($c['json']) ? count($c['json']) : 0;
echo $standard >= 395 ? "standard_ok\n" : "standard_bad\n";
echo $date >= 17 ? "date_ok\n" : "date_bad\n";
echo $json >= 29 ? "json_ok\n" : "json_bad\n";
echo array_key_exists('user', $c) ? "user_bad\n" : "user_ok\n";
foreach ($c as $k => $v) {
    // Must not fatal (issue #4840 Unknown index type 7).
}
echo "foreach_ok\n";
--EXPECT--
standard_ok
date_ok
json_ok
user_ok
foreach_ok
