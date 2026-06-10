--TEST--
AOT: getenv() zero-argument form returns environment array (#5075)
--ENV--
APP_DEBUG=1
--FILE--
<?php
$all = getenv();
echo is_array($all) ? "array\n" : "not-array\n";
echo count($all) > 0 ? "nonempty\n" : "empty\n";
echo (isset($all['PATH']) || isset($all['HOME'])) ? "has-path-or-home\n" : "missing-path-home\n";
if (isset($all['APP_DEBUG'])) {
    echo $all['APP_DEBUG'], "\n";
} else {
    echo "missing-app-debug\n";
}
putenv('APP_ENV=production');
$all2 = getenv();
if (isset($all2['APP_ENV'])) {
    echo $all2['APP_ENV'], "\n";
} else {
    echo "missing-app-env\n";
}
--EXPECT--
array
nonempty
has-path-or-home
1
production
