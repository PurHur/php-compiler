--TEST--
Language: continue outside loop/switch — compile-time fatal (#5447)
--FILE--
<?php
continue;
--EXPECT_EXIT--
255
