--TEST--
CLI: display_errors=0 keeps uncaught fatal on stderr only (#18561, sapi/cli/php_cli.c)
--FILE--
<?php
declare(strict_types=1);
ini_set('display_errors', '0');
explode('', 'a');
--EXPECTF--
PHP Fatal error:  Uncaught ValueError: explode(): Argument #1 ($separator) cannot be empty in %s:%d
Stack trace:
#0 %s(%d): explode()
#1 {main}
  thrown in %s on line %d
--EXPECT_EXIT--
255
