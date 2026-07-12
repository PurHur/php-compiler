--TEST--
Language: exit(status:)/die(status:) named parameters on PHP 8.4 forward profile (#17681, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
exit(status: 0);
--EXPECT_EXIT--
0
