--TEST--
stdlib ini_set() minimal subset (issue #1374)
--FILE--
<?php
/** Supported keys: error_reporting, display_errors, memory_limit */
$oldEr = ini_set('error_reporting', '0');
echo is_string($oldEr) ? "er-old\n" : "er-bad\n";
echo ini_set('not_a_real_ini_option', 'x') === false ? "unknown-false\n" : "unknown-bad\n";
$unlimited = '-'.'1';
echo ini_set('memory_limit', $unlimited) === false ? "ml-fail\n" : "ml-ok\n";
echo ini_get('memory_limit') === $unlimited ? "ml-get\n" : "ml-get-fail\n";
--EXPECT--
er-old
unknown-false
ml-ok
ml-get
