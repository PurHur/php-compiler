<?php
// AOT probe #23262 — array_is_list / array_key_* named array:
echo array_is_list(array: [0, 1]) ? "1\n" : "0\n";
echo array_key_first(array: ['a' => 1, 'b' => 2]), "\n";
echo array_key_last(array: ['a' => 1, 'b' => 2]), "\n";
echo array_is_list([0, 1]) ? "1\n" : "0\n";
