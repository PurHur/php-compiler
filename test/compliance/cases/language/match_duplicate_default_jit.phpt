--TEST--
Language: match duplicate default arm — JIT compile-time fatal (#4697)
--FILE--
<?php
$x = match (1) {
    default => 1,
    default => 2,
};
var_export($x);
--EXPECT_EXIT--
255
