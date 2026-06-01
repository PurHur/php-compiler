--TEST--
stdlib print_r() — array echo and return string (#3133)
--FILE--
<?php
$a = ['k' => 1, 'nested' => ['x' => 2]];
echo print_r($a, true);
echo "---\n";
print_r($a);
echo "---\n";
var_dump(print_r(42, true));
--EXPECT--
Array
(
    [k] => 1
    [nested] => Array
        (
            [x] => 2
        )

)
---
Array
(
    [k] => 1
    [nested] => Array
        (
            [x] => 2
        )

)
---
string(2) "42"
