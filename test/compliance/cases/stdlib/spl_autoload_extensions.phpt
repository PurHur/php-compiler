--TEST--
stdlib spl_autoload_extensions() JIT + VM (#4256, ext/spl/php_spl.c)
--FILE--
<?php
declare(strict_types=1);

echo spl_autoload_extensions(), "\n";
spl_autoload_extensions('.test,.phpt');
echo spl_autoload_extensions(), "\n";
spl_autoload_extensions(null);
echo spl_autoload_extensions(), "\n";
--EXPECT--
.inc,.php
.test,.phpt
.inc,.php
