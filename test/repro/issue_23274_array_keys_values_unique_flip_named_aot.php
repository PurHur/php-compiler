<?php
// AOT probe #23274 — named array:/filter_value: compile without Unknown named parameter
// Runtime may still be thin-helper limited for some array builtins under AOT (pre-existing).
$k = array_keys(array: ['a' => 1, 'b' => 2]);
$kf = array_keys(array: ['a' => 1, 'b' => 2], filter_value: 2);
$v = array_values(array: ['a' => 1]);
$pos = array_keys(['a' => 1]);
echo 'named_ok', "\n";
echo (is_array($k) && is_array($kf) && is_array($v) && is_array($pos)) ? "1\n" : "0\n";
