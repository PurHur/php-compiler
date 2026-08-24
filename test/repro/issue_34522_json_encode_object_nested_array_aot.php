<?php
// Issue #34522 — FORCE_OBJECT must not leak into nested array values.
echo json_encode((object)['a' => 1, 'b' => [2]]), "\n";
echo json_encode(new ArrayObject(['x' => [1, 2]])), "\n";
$o = (object)['x' => [1, 2]];
echo json_encode($o), "\n";
