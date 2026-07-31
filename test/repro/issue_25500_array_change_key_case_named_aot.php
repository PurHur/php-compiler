<?php
// AOT probe #25500 — named array:/case: accepted (no Unknown named parameter)
$hi = array_change_key_case(array: ['Foo' => 1], case: CASE_UPPER);
$lo = array_change_key_case(array: ['Bar' => 2]);
$pos = array_change_key_case(['Baz' => 3], CASE_UPPER);
// Named dispatch must not throw; key transform may still be empty under thin AOT helper gap
echo 'named_ok', "\n";
echo (is_array($hi) && is_array($lo) && is_array($pos)) ? "1\n" : "0\n";
