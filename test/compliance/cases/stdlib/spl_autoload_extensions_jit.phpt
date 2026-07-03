--TEST--
stdlib spl_autoload_extensions() JIT (#4256, ext/spl/php_spl.c)
--SKIPIF--
<?php die('skip — compiler JIT compliance via JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

echo spl_autoload_extensions(), "\n";
spl_autoload_extensions('.jit');
echo spl_autoload_extensions(), "\n";
--EXPECT--
.inc,.php
.jit
