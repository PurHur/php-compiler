--TEST--
AOT getrusage() — resource usage array shape (issue #3240 / #27551)
--FILE--
<?php
$u = getrusage();
if (!isset($u['ru_maxrss']) || !isset($u['ru_utime.tv_sec'])) {
    echo "bad_shape\n";
} else {
    echo "shape\n";
}
--EXPECT--
shape
