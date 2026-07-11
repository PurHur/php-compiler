--TEST--
Language: exit(status:)/die(status:) named parameter (#13487, Zend/zend_compile.c PHP 8.4)
--FILE--
<?php
exit(status: 42);
--EXPECT_EXIT--
42
