<?php
// repro #27183 — AOT array_change_key_case string keys (thin HELPER_RUNTIME_O=0)
echo json_encode(array_change_key_case(['Foo' => 1, 'Bar' => 2], CASE_LOWER)), "\n";
echo json_encode(array_change_key_case(['Foo' => 1, 'Bar' => 2], CASE_UPPER)), "\n";
echo json_encode(array_change_key_case(['Foo' => 1, 2 => 9], CASE_LOWER)), "\n";
