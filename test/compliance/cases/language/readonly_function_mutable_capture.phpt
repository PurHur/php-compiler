--TEST--
Language: readonly closure mutable use() capture — compile-time fatal (#7428)
--FILE--
<?php
$x = 1;
readonly function () use ($x) {};
--EXPECT_EXIT--
255
