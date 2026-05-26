--TEST--
stdlib ksort() on string-keyed associative arrays (issue #2271)
--FILE--
<?php
$data = ['b' => 2, 'a' => 1, 'c' => 3];
ksort($data);
foreach ($data as $key => $value) {
    echo $key, ':', $value, "\n";
}
--EXPECT--
a:1
b:2
c:3
