--TEST--
stdlib array_multisort() assign-in-arg first operand + SORT flags — no TypeError (#15154)
--FILE--
<?php
array_multisort($a = [3, 1, 2], SORT_ASC, SORT_NUMERIC);
echo json_encode($a), "\n";
$b = [3, 1, 2];
array_multisort($b, SORT_ASC, SORT_NUMERIC);
echo json_encode($b), "\n";
--EXPECT--
[3,1,2]
[1,2,3]
