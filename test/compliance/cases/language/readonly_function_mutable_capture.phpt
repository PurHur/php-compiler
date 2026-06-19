--TEST--
Language: readonly closure rejected — php-src parse error (#10012, was #7428)
--FILE--
<?php
$x = 1;
readonly function () use ($x) {};
--EXPECT_EXIT--
255
