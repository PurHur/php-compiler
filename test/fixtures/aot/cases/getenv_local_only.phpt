--TEST--
AOT: getenv() local_only second argument (issue #3710)
--FILE--
<?php
putenv('PHP_COMPILER_LOCAL_ONLY_TEST=from_putenv');
echo getenv('PHP_COMPILER_LOCAL_ONLY_TEST', false), "\n";
echo getenv('PHP_COMPILER_LOCAL_ONLY_TEST', true), "\n";
echo getenv('PATH', true) === false ? "false\n" : "set\n";
--EXPECT--
from_putenv
from_putenv
false
