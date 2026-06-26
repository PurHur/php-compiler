--TEST--
stdlib print_r() circular array — *RECURSION* guard not OOM (issue #11179)
--FILE--
<?php
$a = [];
$a[0] = &$a;
echo print_r($a, true);
echo "---\n";
$o = new stdClass();
$o->x = &$o;
echo print_r($o, true);
--EXPECT--
Array
(
    [0] => Array
 *RECURSION*
)
---
stdClass Object
(
    [x] => stdClass Object
 *RECURSION*
)
