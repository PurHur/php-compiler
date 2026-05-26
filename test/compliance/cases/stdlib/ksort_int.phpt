--TEST--
stdlib ksort() on integer-key associative arrays (issue #2271)
--FILE--
<?php
$data = [30 => 'c', 10 => 'a', 20 => 'b'];
ksort($data);
foreach ($data as $key => $value) {
    echo $key, ':', $value, "\n";
}
--EXPECT--
10:a
20:b
30:c
