--TEST--
AOT getrusage() — resource usage array shape (issue #3240)
--FILE--
<?php
$u = getrusage();
if (!isset($u['ru_maxrss'])) {
    echo "bad_shape\n";
} else {
    echo "shape\n";
}
--EXPECT--
shape
