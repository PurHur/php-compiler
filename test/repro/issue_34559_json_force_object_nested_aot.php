<?php
// Issue #34559 — user JSON_FORCE_OBJECT must apply to nested arrays (runtime, not foldable).
function make_list()
{
    return [1, [2, [3]]];
}
echo json_encode(make_list(), JSON_FORCE_OBJECT), "\n";
$o = (object) ['a' => [1, 2]];
echo json_encode($o, JSON_FORCE_OBJECT), "\n";
echo json_encode(new ArrayObject(['a' => [1, 2]]), JSON_FORCE_OBJECT), "\n";
// #34522 must stay: no user FORCE_OBJECT → nested arrays remain arrays
echo json_encode((object) ['a' => [1, 2]]), "\n";
echo json_encode(new ArrayObject(['a' => [1, 2]])), "\n";
