--TEST--
get_defined_constants(true) extension module buckets (#4840, Zend/zend_builtin_functions.c)
--FILE--
<?php
$c = get_defined_constants(true);
echo isset($c['standard']) && count($c['standard']) > 100 ? "standard_ok\n" : "standard_bad\n";
echo isset($c['Core']) && count($c['Core']) > 50 && count($c['Core']) < 120 ? "core_ok\n" : "core_bad\n";
echo array_key_exists('user', $c) ? "user_bad\n" : "user_ok\n";
foreach (['STDIN', 'STDOUT', 'STDERR'] as $name) {
    if (!isset($c['Core'][$name])) {
        echo "stdio_bad\n";
        exit(0);
    }
}
echo "stdio_ok\n";
foreach ($c as $k => $v) {
    // Must not fatal (issue #4840 Unknown index type 7).
}
echo "foreach_ok\n";
--EXPECT--
standard_ok
core_ok
user_ok
stdio_ok
foreach_ok
