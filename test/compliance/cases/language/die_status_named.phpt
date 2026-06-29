--TEST--
Language: die(status:) named parameter (#13487, Zend/zend_compile.c PHP 8.4)
--FILE--
<?php
die(status: 7);
--EXPECT_EXIT--
7
