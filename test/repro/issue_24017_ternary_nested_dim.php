<?php
/**
 * After a ternary that type-asserts `$t`, nested `$t[1][0]` inside a later echo ternary
 * must still see `$t` (Zend FETCH_DIM_R). JUMPIF dead-temp release must not null a temp
 * that aliases the named local (#24017).
 */
$t = [10, [291, 'echo', 1], 20];
echo is_array($t) ? count($t) : 0, "\n";
echo is_array($t[1]) ? $t[1][0] : 'str', "\n";
