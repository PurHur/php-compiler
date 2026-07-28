<?php
// #24161 — AOT str_starts_with must match Zend (memcmp prefix; was always-false via NestedJIT compareBytes).
$s = '  Hello World  ';
var_dump(str_starts_with('Hello World', 'Hello'));
var_dump(str_starts_with(trim($s), 'Hello'));
var_dump(str_contains(trim($s), 'World'));
