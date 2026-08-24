<?php
/** AOT: json_encode() must honor $depth → false + JSON_ERROR_DEPTH (#34544). */

echo "== literal_depth2 ==\n";
var_dump(json_encode([1, [2, [3]]], 0, 2));
var_dump(json_last_error());
var_dump(json_last_error_msg());

echo "== runtime_array ==\n";
$a = [1, [2, [3]]];
var_dump(json_encode($a, 0, 2));
var_dump(json_last_error_msg());

echo "== runtime_depth ==\n";
$d = 2;
var_dump(json_encode([1, [2, [3]]], 0, $d));
var_dump(json_last_error_msg());

echo "== depth_zero_empty ==\n";
var_dump(json_encode([], 0, 0));
var_dump(json_last_error_msg());

echo "== within_depth ==\n";
echo json_encode([1, [2]], 0, 2), "\n";
var_dump(json_last_error_msg());
