<?php
// Issue #28638 — AOT json_encode(stdClass) must emit JSON object, not "stdClass".
$o = new stdClass;
$o->a = 1;
$o->b = 'x';
echo json_encode($o), "\n";
echo json_encode(['a' => 1, 'b' => [2, 3]]), "\n";
