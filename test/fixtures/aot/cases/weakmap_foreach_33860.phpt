--TEST--
AOT: WeakMap foreach walks object keys (#33860)
--FILE--
<?php
$m = new WeakMap();
foreach ($m as $k => $v) {
    echo "unexpected\n";
}
echo "empty_ok\n";

$o = new stdClass();
$m[$o] = 'v';
foreach ($m as $k => $v) {
    echo get_class($k), ':', $v, "\n";
}
echo 'count=', count($m), "\n";
--EXPECT--
empty_ok
stdClass:v
count=1
