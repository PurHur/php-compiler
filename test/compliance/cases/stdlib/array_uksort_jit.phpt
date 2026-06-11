--TEST--
stdlib array_uksort() JIT strcmp on string keys (issue #5649)
--FILE--
<?php
$data = ['b' => 1, 'a' => 2];
array_uksort($data, 'strcmp');
echo implode(',', array_keys($data)), "\n";
--EXPECT--
a,b
