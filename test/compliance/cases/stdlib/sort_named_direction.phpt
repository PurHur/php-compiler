--TEST--
stdlib sort()/rsort() reject phantom named direction (issue #23225; vs php-src)
--FILE--
<?php
$a = [2, 1];
sort($a, flags: SORT_REGULAR);
echo implode(',', $a), "\n";
try {
    $b = [2, 1];
    sort($b, direction: 1);
    echo "direction_accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
1,2
Unknown named parameter $direction
