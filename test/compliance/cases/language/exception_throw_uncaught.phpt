--TEST--
Language: uncaught Exception yields non-zero VM CLI exit (issue #195)
--FILE--
<?php
throw new Exception('boom');
--EXPECT_EXIT--
255
