--TEST--
bootstrap getenv() zero-arg uses native environ walk (#5079, pairs #5075)
--ENV--
APP_DEBUG=1
--FILE--
<?php
$all = getenv();
echo is_array($all) ? "array\n" : "not-array\n";
echo count($all) > 0 ? "nonempty\n" : "empty\n";
echo (array_key_exists('PATH', $all) || array_key_exists('HOME', $all)) ? "has-path-or-home\n" : "missing-path-home\n";
echo array_key_exists('APP_DEBUG', $all) ? $all['APP_DEBUG'] : 'missing-app-debug', "\n";
putenv('APP_ENV=production');
$all2 = getenv();
echo array_key_exists('APP_ENV', $all2) ? $all2['APP_ENV'] : 'missing-app-env', "\n";
--EXPECT--
array
nonempty
has-path-or-home
1
production
