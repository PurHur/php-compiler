--TEST--
Language: uncaught throw yields non-zero VM CLI exit (#2084, #195)
--FILE--
<?php
class Ex {}
throw new Ex();
--EXPECT_EXIT--
255
