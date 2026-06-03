--TEST--
Language: match default arm not last — JIT compile-time fatal (#5061)
--FILE--
<?php
echo match (1) {
    default => 'd',
    1 => 'a',
};
--EXPECT_EXIT--
255
