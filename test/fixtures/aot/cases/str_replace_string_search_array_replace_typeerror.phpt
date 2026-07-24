--TEST--
AOT: str_replace() string $search + array $replace TypeError aborts (#22827)
--FILE--
<?php
str_replace('a', ['x', 'y'], 'a');
--EXPECT--
--EXPECT_EXIT--
255
