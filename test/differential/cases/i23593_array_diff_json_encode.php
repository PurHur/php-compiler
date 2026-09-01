<?php
// #23593 — json_encode(array_diff()) must preserve sparse int keys (ext/standard/array.c).
echo json_encode(array_diff([1, 2, 3], [2])), "\n";
