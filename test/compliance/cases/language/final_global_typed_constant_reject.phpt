--TEST--
Language: final global typed constants rejected — Zend parse error (#10324, #15185, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

final const string APP_NAME = 'alpha';

echo APP_NAME, "\n";
--EXPECT_EXIT--
255
