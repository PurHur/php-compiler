--TEST--
CLI: display_errors=1 mirrors uncaught fatal to stdout without PHP prefix (#18561, sapi/cli/php_cli.c)
--FILE--
<?php
declare(strict_types=1);
ini_set('display_errors', '1');
explode('', 'a');
--EXPECTF--
Fatal error: Uncaught ValueError: explode(): Argument #1 ($separator) cannot be empty in %s:%d
Stack trace:
#0 %s(%d): explode()
#1 {main}
  thrown in %s on line %d
--EXPECT_EXIT--
255
