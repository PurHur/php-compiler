--TEST--
Language: break outside loop/switch — compile-time fatal (#5447)
--FILE--
<?php
break;
--EXPECT_EXIT--
255
