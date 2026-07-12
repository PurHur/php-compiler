--TEST--
Language: die(status:) named parameter on PHP 8.4 forward profile (#17681, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
die(status: 2);
--EXPECT_EXIT--
2
