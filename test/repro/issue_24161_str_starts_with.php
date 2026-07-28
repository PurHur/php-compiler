<?php
// #24161 — AOT str_starts_with / str_ends_with must match Zend (literals + trim subject).
$s = '  Hello World  ';
var_dump(str_starts_with('Hello World', 'Hello'));
var_dump(str_starts_with(trim($s), 'Hello'));
var_dump(str_ends_with('Hello World', 'World'));
var_dump(str_contains(trim($s), 'World'));
var_dump(str_starts_with('Hello', 'X'));
var_dump(str_starts_with('Hello', ''));
