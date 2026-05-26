--TEST--
stdlib ksort() on integer assoc keys (issue #2271)
--FILE--
<?php
$data = [2 => 'b', 1 => 'a', 3 => 'c'];
ksort($data);
foreach ($data as $key => $value) {
    echo $key, ':', $value, "\n";
}
--EXPECT--
1:a
2:b
3:c
