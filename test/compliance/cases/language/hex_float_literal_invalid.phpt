--TEST--
Language: PHP 8.4 hex float invalid suffix compile error (#7041)
--FILE--
<?php
echo 0x1.8q+1;
--EXPECT_EXIT--
255
