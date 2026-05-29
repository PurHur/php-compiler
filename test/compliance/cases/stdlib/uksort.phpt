--TEST--
stdlib uksort() sorts keys preserving values (issue #3143)
--FILE--
<?php
$data = ['b' => 2, 'a' => 1];
uksort($data, 'strcmp');
foreach ($data as $key => $value) {
    echo $key, ':', $value, "\n";
}
--EXPECT--
a:1
b:2
