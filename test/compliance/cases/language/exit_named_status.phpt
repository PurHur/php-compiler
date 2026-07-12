--TEST--
Language: exit(status:)/die(status:) named parameter (#16082, Zend/zend_compile.c PHP 8.4)
--FILE--
<?php
declare(strict_types=1);
exit(status: 0);
--EXPECT_EXIT--
0
